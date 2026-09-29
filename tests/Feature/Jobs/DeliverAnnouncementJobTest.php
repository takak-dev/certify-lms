<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\DeliverAnnouncementJob;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * お知らせの「配る係」ジョブ(DeliverAnnouncementJob)の検証(T-A-05 / decisions #274)。
 *
 * 見るのは 2 つ。
 * ① 渡された宛先(ID の一覧)の人にだけ、宛先 × チャネルごとの通知ジョブを積むこと
 * ② 展開の途中で失敗したら、それまでに積んだ分も巻き戻ること(やり直しで同じ人へ二重に積まない)
 *
 * どちらもキューの接続を本番と同じ database にして確かめる。
 * テスト既定(phpunit.xml の QUEUE_CONNECTION=sync)のままだと、通知ジョブが積まれずその場で送られてしまう。
 */
class DeliverAnnouncementJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    public function test_fans_out_to_the_given_recipients_only(): void
    {
        // Arrange: 宛先 2 人と、宛先に含めない 1 人。
        //          含めない人も受講中にしておく——「受講中の全員」ではなく「渡した ID の人」に配ることを見るため
        $announcement = Announcement::factory()->create();
        [$a, $b] = User::factory()->student()->inProgress()->count(2)->create()->all();
        $notIncluded = User::factory()->student()->inProgress()->create();

        // Act: worker が実行するのと同じ handle() を直接呼ぶ
        (new DeliverAnnouncementJob($announcement, [$a->id, $b->id]))->handle();

        // Assert: 2 人 × 2 チャネル(database / mail) = 4 件。宛先に含めない人の分は無い
        $recipientIds = DB::table('jobs')->pluck('payload')
            ->map(fn (string $payload) => unserialize(json_decode($payload, true)['data']['command']))
            ->map(fn ($job) => $job->notifiables->first()->id)
            ->all();
        $this->assertCount(4, $recipientIds);
        $this->assertEqualsCanonicalizing([$a->id, $a->id, $b->id, $b->id], $recipientIds);
        $this->assertNotContains($notIncluded->id, $recipientIds);
    }

    public function test_rolls_back_already_queued_jobs_when_fan_out_fails_midway(): void
    {
        // Arrange: 宛先 2 人(= 通知ジョブ 4 件)。3 件目を積んだ直後に失敗させる。
        //          JobQueued は Laravel がジョブを 1 件積むたびに出す合図
        //          (vendor/laravel/framework/src/Illuminate/Queue/Queue.php:392-395)。
        //          ここで例外を投げると「途中まで積んだところで落ちた」状態を作れる
        $announcement = Announcement::factory()->create();
        $ids = User::factory()->student()->inProgress()->count(2)->create()->pluck('id')->all();

        $queued = 0;
        Event::listen(JobQueued::class, function () use (&$queued): void {
            if (++$queued === 3) {
                throw new RuntimeException('展開の途中で失敗したことにする');
            }
        });

        // Act
        try {
            (new DeliverAnnouncementJob($announcement, $ids))->handle();
            $this->fail('例外が起きていない。失敗の注入が効いていない');
        } catch (RuntimeException) {
            // 失敗させるための例外なので、ここでは握りつぶす
        }

        // Assert: ① 失敗する前に 3 件は積まれていた(ここが崩れると下の検査が意味を失う)
        $this->assertSame(3, $queued);
        // ② それでも棚は空。展開をトランザクションで包んでいるので、途中まで積んだ分も巻き戻った。
        //    巻き戻らないと、worker がこのジョブをやり直したときに同じ人へ二重に積まれる
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('notifications')->count());
    }
}
