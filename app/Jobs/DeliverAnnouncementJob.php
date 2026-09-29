<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use App\Notifications\RetriesWithBackoff;
use App\UseCases\Announcement\StoreAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * お知らせの一斉配信を、宛先ごとの通知ジョブに展開する「配る係」のジョブ(T-A-05 / decisions #274)。
 *
 * ⭐ なぜ 1 段挟むのか。
 * `Notification::send()` は「宛先 × チャネル」ごとに jobs へ 1 行 INSERT する
 * (Illuminate/Notifications/NotificationSender.php:188-235)。画面の処理の中で呼ぶと、
 * 受講生 200 人で 400 行・約 1.3 秒かかり(実測)、待ち時間が人数に比例して伸びる。
 * 画面の処理ではこのジョブを 1 件積むだけにし、400 行を積む作業は worker 側に移す。
 *
 * 宛先は配信ボタンを押した時点で確定させ、User の ID の一覧として受け取る。
 * worker 側で決め直すと、押してから配るまでに受講生が増減したとき、
 * 配信記録の `dispatched_count`(decisions #92)と実際の相手がずれるため。
 * 押した後に退会した人は論理削除されるので、展開の時点で外れる(User の SoftDeletes)。
 * 修了など状態だけが変わった人は、送信直前の判定(DeliversToActiveUsersOnly)で外れる。
 *
 * @see StoreAction
 */
final class DeliverAnnouncementJob implements ShouldQueue
{
    // Dispatchable: DeliverAnnouncementJob::dispatch(...) で積めるようにする
    // InteractsWithQueue / Queueable: キューとのやり取り(何回目か・接続の指定など)
    // SerializesModels: Announcement を「クラス名と ID」だけにして積む(本文そのものは jobs に残らない)
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 失敗したら待ってやり直す(最大 4 回・10 秒 → 60 秒 → 300 秒。通知と同じ決まり。decisions #271)
    use RetriesWithBackoff;

    /** 1 回の問い合わせで読む宛先の人数。1 万人でもメモリに全員を載せないため */
    private const CHUNK_SIZE = 500;

    /**
     * @param array<int, string> $recipientIds 配信ボタンを押した時点で確定した宛先(User の ULID)
     */
    public function __construct(
        public readonly Announcement $announcement,
        public readonly array $recipientIds,
    ) {}

    public function handle(): void
    {
        // ⚠️ 展開をトランザクションで包む。途中で失敗してやり直したとき、同じ人へ二重に積まないため。
        // database キューは同じ DB 接続の jobs に INSERT するので、巻き戻れば途中まで積んだ分も消える
        DB::transaction(function (): void {
            User::query()
                ->whereIn('id', $this->recipientIds)
                ->chunkById(self::CHUNK_SIZE, function (Collection $recipients): void {
                    // ここで「宛先 × チャネル」ごとの通知ジョブが積まれる(画面の処理から移した部分)
                    Notification::send($recipients, new AdminAnnouncementNotification($this->announcement));
                });
        });
    }
}
