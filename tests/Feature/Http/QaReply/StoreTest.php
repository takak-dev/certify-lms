<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaReply;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 回答の投稿（POST /qa-board/{thread}/replies）の検証。
 *
 * 原典が明示している「管理者は回答できない」と、面談1で確定した
 * 「コーチは担当資格のみ」（decisions #40）をここで固定する。
 * 回答できる範囲はスレッドの閲覧可否に従うため、QaReplyPolicy は QaThreadPolicy::view に委譲している。
 */
class StoreTest extends TestCase
{
    use RefreshDatabase;

    private function publishedThread(): QaThread
    {
        $certification = Certification::factory()->published()->create();

        return QaThread::factory()->forCertification($certification)->create();
    }

    private function assignCoach(Certification $certification, User $coach): void
    {
        // 中間テーブルは ULID 主キーと割当メタ情報を持つ（手本: MockExamPolicyTest.php:43-47）
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_student_can_post_reply(): void
    {
        // Arrange
        $thread = $this->publishedThread();
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)->post(route('qa-board.replies.store', $thread), [
            'body' => '同じところでつまずきました。私はこう解決しました。',
        ]);

        // Assert: 回答は独立した画面を持たないため、常に親スレッドの詳細へ戻る
        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
        ]);
    }

    public function test_assigned_coach_can_post_reply(): void
    {
        // Arrange: 担当資格のスレッドには回答できる
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->forCertification($certification)->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);

        // Act
        $response = $this->actingAs($coach)->post(route('qa-board.replies.store', $thread), [
            'body' => 'まずは用語を図に書き出してみてください。',
        ]);

        // Assert
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('qa_replies', ['qa_thread_id' => $thread->id, 'user_id' => $coach->id]);
    }

    public function test_coach_cannot_post_reply_to_unassigned_certification(): void
    {
        // Arrange: 担当外の資格は「操作できない」（decisions #40。一覧に出さず、詳細は 403）
        $thread = $this->publishedThread();
        $coach = User::factory()->coach()->create();

        // Act & Assert
        $this->actingAs($coach)->post(route('qa-board.replies.store', $thread), [
            'body' => '担当外なので投稿できないはず',
        ])->assertForbidden();
        $this->assertDatabaseCount('qa_replies', 0);
    }

    public function test_admin_cannot_post_reply(): void
    {
        // Arrange: 原典「受講生・コーチによる回答の投稿(管理者は回答できない)」。
        // 管理者は公開ルートに入れないため、ミドルウェアの時点で 403 になる
        $thread = $this->publishedThread();
        $admin = User::factory()->admin()->create();

        // Act & Assert
        $this->actingAs($admin)->post(route('qa-board.replies.store', $thread), [
            'body' => '管理者は回答できないはず',
        ])->assertForbidden();
        $this->assertDatabaseCount('qa_replies', 0);
    }

    public function test_body_is_required_and_limited_to_five_thousand_characters(): void
    {
        // Arrange: 上限は支給 Blade の :maxlength="5000"（_reply-form.blade.php:10-18）に合わせる
        $thread = $this->publishedThread();
        $student = User::factory()->student()->create();

        // Act & Assert: 空は必須エラー
        $this->actingAs($student)->post(route('qa-board.replies.store', $thread), ['body' => ''])
            ->assertSessionHasErrors('body');

        // Act & Assert: 上限ちょうどは通る（境界値）
        $this->actingAs($student)->post(route('qa-board.replies.store', $thread), ['body' => str_repeat('あ', 5000)])
            ->assertSessionHas('success');

        // Act & Assert: 1文字超えると弾かれる
        $this->actingAs($student)->post(route('qa-board.replies.store', $thread), ['body' => str_repeat('あ', 5001)])
            ->assertSessionHasErrors('body');
    }

    public function test_reply_cannot_be_posted_to_thread_of_unpublished_certification(): void
    {
        // Arrange: 公開停止資格のスレッドは受講生・コーチから見えない。回答も投稿できない
        $archived = Certification::factory()->archived()->create();
        $thread = QaThread::factory()->forCertification($archived)->create();
        $student = User::factory()->student()->create();

        // Act & Assert
        $this->actingAs($student)->post(route('qa-board.replies.store', $thread), ['body' => '見えないはずの場所への回答'])
            ->assertForbidden();
        $this->assertDatabaseCount('qa_replies', 0);
    }
}
