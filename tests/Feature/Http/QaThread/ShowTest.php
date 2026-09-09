<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * スレッド詳細（GET /qa-board/{thread}、GET /admin/qa-board/{thread}）の検証。
 *
 * decisions #40 は「一覧に表示しない。詳細は 403」と 2 段構えで確定している。
 * 一覧側は IndexTest が守るので、ここでは詳細側（QaThreadPolicy::view）を固定する。
 * 一覧だけ塞いで詳細が素通しになる、という抜け方を防ぐのが目的。
 */
class ShowTest extends TestCase
{
    use RefreshDatabase;

    private function assignCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_student_can_open_thread_of_published_certification(): void
    {
        // Arrange: 受講登録は前提にしない（原典「公開済資格すべてのスレッドを閲覧・投稿できる」）
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->forCertification($certification)->create();
        $student = User::factory()->student()->create();

        // Act & Assert
        $this->actingAs($student)->get(route('qa-board.show', $thread))->assertOk();
    }

    public function test_coach_cannot_open_thread_of_unassigned_certification(): void
    {
        // Arrange: 担当外の資格。一覧に出さないだけでなく、URL を直接叩いても開けない（decisions #40）
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->forCertification($certification)->create();
        $coach = User::factory()->coach()->create();

        // Act & Assert
        $this->actingAs($coach)->get(route('qa-board.show', $thread))->assertForbidden();
    }

    public function test_assigned_coach_can_open_thread(): void
    {
        // Arrange: 担当資格なら開ける（過剰に拒否していないことの確認）
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->forCertification($certification)->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);

        // Act & Assert
        $this->actingAs($coach)->get(route('qa-board.show', $thread))->assertOk();
    }

    public function test_author_cannot_open_thread_of_unpublished_certification(): void
    {
        // Arrange: 公開停止資格のスレッドは投稿者本人でも見えない（pending Q21 の暫定判断）。
        // スレッドには他の受講生の回答も含まれるため、投稿者だけに見せると他人の投稿まで見えてしまう
        $archived = Certification::factory()->archived()->create();
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->forUser($author)->forCertification($archived)->create();

        // Act & Assert
        $this->actingAs($author)->get(route('qa-board.show', $thread))->assertForbidden();
    }

    public function test_admin_can_open_thread_of_unpublished_certification_on_moderation_screen(): void
    {
        // Arrange: 管理者だけが公開停止資格のスレッドを閲覧できる（原典のアクセス制御）
        $archived = Certification::factory()->archived()->create();
        $thread = QaThread::factory()->forCertification($archived)->create();
        $admin = User::factory()->admin()->create();

        // Act & Assert
        $this->actingAs($admin)->get(route('admin.qa-board.show', $thread))->assertOk();
    }

    public function test_thread_carries_reply_count_for_the_detail_badge(): void
    {
        // Arrange: show.blade.php:36 が $thread->replies_count を読む。
        // loadCount が外れると null になり、回答0件のバッジ出し分けが壊れる
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->forCertification($certification)->create();
        QaReply::factory()->forThread($thread)->count(3)->create();
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)->get(route('qa-board.show', $thread));

        // Assert
        $response->assertOk();
        $response->assertViewHas('thread', fn (QaThread $t) => $t->replies_count === 3);
    }

    public function test_withdrawn_author_name_is_still_shown(): void
    {
        // Arrange: 退会は論理削除。氏名はそのまま表示し続ける（decisions #67）。
        // リレーションに withTrashed が無いと $thread->user が null になり「不明」表示になる
        $certification = Certification::factory()->published()->create();
        $author = User::factory()->student()->create(['name' => '退会した受講生']);
        $thread = QaThread::factory()->forUser($author)->forCertification($certification)->create();
        $reply = QaReply::factory()->forThread($thread)->forUser($author)->create();

        $author->delete(); // 論理削除

        $viewer = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($viewer)->get(route('qa-board.show', $thread));

        // Assert: スレッドと回答の両方で氏名が引ける
        $response->assertOk();
        $response->assertViewHas('thread', function (QaThread $t) use ($reply) {
            return $t->user?->name === '退会した受講生'
                && $t->replies->firstWhere('id', $reply->id)?->user?->name === '退会した受講生';
        });
    }
}
