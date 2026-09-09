<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 入力画面（GET）の認可を固定する。
 *
 * 原典の HTTP 表は GET も認可主体を明示している:
 * `/qa-board/create` は受講生のみ、`/qa-board/{thread}/edit` と
 * `/qa-board/{thread}/replies/{reply}/edit` は投稿者本人のみ。
 *
 * これらは POST / PATCH と違い FormRequest を持たず、Controller の `$this->authorize()` が
 * 唯一の防御になる。そこが外れても他のテストは緑のままなので、ここで直接固定する。
 */
class FormScreenTest extends TestCase
{
    use RefreshDatabase;

    private function publishedThread(?User $author = null): QaThread
    {
        $certification = Certification::factory()->published()->create();
        $author ??= User::factory()->student()->create();

        return QaThread::factory()->forUser($author)->forCertification($certification)->create();
    }

    public function test_create_screen_is_for_students_only(): void
    {
        // Arrange: 原典「GET /qa-board/create 受講生のみ」
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        // Act & Assert: コーチは Policy で 403、管理者はルートのミドルウェアで 403
        $this->actingAs($student)->get(route('qa-board.create'))->assertOk();
        $this->actingAs($coach)->get(route('qa-board.create'))->assertForbidden();
        $this->actingAs($admin)->get(route('qa-board.create'))->assertForbidden();
    }

    public function test_thread_edit_screen_is_for_the_author_only(): void
    {
        // Arrange: 原典「GET /qa-board/{thread}/edit 投稿者本人のみ」
        $author = User::factory()->student()->create();
        $thread = $this->publishedThread($author);
        $otherStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        // Act & Assert
        $this->actingAs($author)->get(route('qa-board.edit', $thread))->assertOk();
        $this->actingAs($otherStudent)->get(route('qa-board.edit', $thread))->assertForbidden();
        $this->actingAs($coach)->get(route('qa-board.edit', $thread))->assertForbidden();
    }

    public function test_reply_edit_screen_is_for_the_author_only(): void
    {
        // Arrange: 原典「GET /qa-board/{thread}/replies/{reply}/edit 投稿者本人のみ」。
        // この画面は支給 Blade のリンクからしか辿れないため、認可が外れても気づきにくい
        $thread = $this->publishedThread();
        $author = User::factory()->student()->create();
        $reply = QaReply::factory()->forThread($thread)->forUser($author)->create();
        $otherStudent = User::factory()->student()->create();

        $params = ['thread' => $thread->id, 'reply' => $reply->id];

        // Act & Assert
        $this->actingAs($author)->get(route('qa-board.replies.edit', $params))->assertOk();
        $this->actingAs($otherStudent)->get(route('qa-board.replies.edit', $params))->assertForbidden();
    }

    public function test_reply_edit_screen_returns_not_found_when_thread_does_not_match(): void
    {
        // Arrange: 別スレッドの回答 ID を指した URL。destroy 経路と同じ扱い（404）になることを確認する
        $threadA = $this->publishedThread();
        $threadB = $this->publishedThread();
        $author = User::factory()->student()->create();
        $replyOfB = QaReply::factory()->forThread($threadB)->forUser($author)->create();

        // Act & Assert
        $this->actingAs($author)
            ->get(route('qa-board.replies.edit', ['thread' => $threadA->id, 'reply' => $replyOfB->id]))
            ->assertNotFound();
    }

    public function test_graduated_coach_cannot_access_the_board(): void
    {
        // Arrange: 原典「受講中の受講生・コーチのみアクセスできる」。
        // 受講生側は IndexTest が見ているので、ここではコーチ側を固定する
        $graduatedCoach = User::factory()->coach()->graduated()->create();

        // Act & Assert: active-learning ミドルウェアが 403 にする
        $this->actingAs($graduatedCoach)->get(route('qa-board.index'))->assertForbidden();
        $this->actingAs($graduatedCoach)->get(route('qa-board.create'))->assertForbidden();
    }

    public function test_keyword_longer_than_the_input_limit_is_rejected(): void
    {
        // Arrange: 検索欄の maxlength="100"（_filter.blade.php:71）に合わせた上限
        $student = User::factory()->student()->create();

        // Act & Assert: 100文字は通り、101文字は弾かれる（境界値）
        $this->actingAs($student)->get(route('qa-board.index', ['keyword' => str_repeat('あ', 100)]))->assertOk();
        $this->actingAs($student)->get(route('qa-board.index', ['keyword' => str_repeat('あ', 101)]))
            ->assertSessionHasErrors('keyword');
    }
}
