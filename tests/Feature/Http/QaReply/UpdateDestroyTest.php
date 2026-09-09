<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaReply;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 回答の編集・削除（PATCH / DELETE /qa-board/{thread}/replies/{reply}）の検証。
 *
 * 面談1で「回答の削除は投稿者本人のみ・条件なし」と確定している（decisions #39）。
 * スレッド側と違い 409 の条件が無いため、その差がテストにも表れる。
 *
 * あわせて、URL の {thread} と {reply} が噛み合わない組み合わせを 404 にすることを固定する。
 * ルートモデルバインディングは既定で親子の整合を検証しないため、Controller 側で確認している
 * （手本: MockExamCatalogController.php:43）。
 */
class UpdateDestroyTest extends TestCase
{
    use RefreshDatabase;

    /** 公開中の資格に紐づくスレッドを作る */
    private function publishedThread(): QaThread
    {
        $certification = Certification::factory()->published()->create();

        return QaThread::factory()->forCertification($certification)->create();
    }

    public function test_author_can_update_own_reply(): void
    {
        // Arrange
        $thread = $this->publishedThread();
        $author = User::factory()->student()->create();
        $reply = QaReply::factory()->forThread($thread)->forUser($author)->create();

        // Act
        $response = $this->actingAs($author)->patch(
            route('qa-board.replies.update', ['thread' => $thread->id, 'reply' => $reply->id]),
            ['body' => '補足を追記しました。']
        );

        // Assert
        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id, 'body' => '補足を追記しました。']);
    }

    public function test_other_user_cannot_update_reply(): void
    {
        // Arrange: 編集できるのは投稿者本人のみ
        $thread = $this->publishedThread();
        $author = User::factory()->student()->create();
        $reply = QaReply::factory()->forThread($thread)->forUser($author)->create();
        $otherStudent = User::factory()->student()->create();

        // Act & Assert: FormRequest の authorize() が 403 にする（Controller には入らない）
        $this->actingAs($otherStudent)->patch(
            route('qa-board.replies.update', ['thread' => $thread->id, 'reply' => $reply->id]),
            ['body' => '他人の回答を書き換える']
        )->assertForbidden();
        $this->assertDatabaseMissing('qa_replies', ['body' => '他人の回答を書き換える']);
    }

    public function test_author_can_delete_own_reply_without_any_condition(): void
    {
        // Arrange: スレッドと違い、回答側には削除条件が無い（decisions #39）
        $thread = $this->publishedThread();
        $author = User::factory()->student()->create();
        $reply = QaReply::factory()->forThread($thread)->forUser($author)->create();

        // Act
        $response = $this->actingAs($author)->delete(
            route('qa-board.replies.destroy', ['thread' => $thread->id, 'reply' => $reply->id])
        );

        // Assert
        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
    }

    public function test_other_user_cannot_delete_reply(): void
    {
        // Arrange
        $thread = $this->publishedThread();
        $author = User::factory()->student()->create();
        $reply = QaReply::factory()->forThread($thread)->forUser($author)->create();
        $otherStudent = User::factory()->student()->create();

        // Act & Assert
        $this->actingAs($otherStudent)->delete(
            route('qa-board.replies.destroy', ['thread' => $thread->id, 'reply' => $reply->id])
        )->assertForbidden();
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
    }

    public function test_admin_can_delete_reply_but_cannot_edit_it(): void
    {
        // Arrange: 管理者はモデレーション削除のみ可。内容の編集はスコープ外（原典）
        $thread = $this->publishedThread();
        $author = User::factory()->student()->create();
        $reply = QaReply::factory()->forThread($thread)->forUser($author)->create();
        $admin = User::factory()->admin()->create();

        // Act & Assert: 編集は公開ルート側にしか無く、管理者はミドルウェアで弾かれる
        $this->actingAs($admin)->patch(
            route('qa-board.replies.update', ['thread' => $thread->id, 'reply' => $reply->id]),
            ['body' => '管理者が書き換える']
        )->assertForbidden();

        // Act & Assert: モデレーション削除は通る
        $this->actingAs($admin)->delete(
            route('admin.qa-board.replies.destroy', ['thread' => $thread->id, 'reply' => $reply->id])
        )->assertRedirect(route('admin.qa-board.show', $thread));
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
    }

    public function test_reply_of_another_thread_returns_not_found(): void
    {
        // Arrange: 別スレッドの回答 ID を指した URL。噛み合わない組み合わせは存在しない資源として扱う
        $threadA = $this->publishedThread();
        $threadB = $this->publishedThread();
        $author = User::factory()->student()->create();
        $replyOfB = QaReply::factory()->forThread($threadB)->forUser($author)->create();

        // Act & Assert: 投稿者本人であっても 404（認可は通るが、URL の親子関係が破綻している）
        $this->actingAs($author)->delete(
            route('qa-board.replies.destroy', ['thread' => $threadA->id, 'reply' => $replyOfB->id])
        )->assertNotFound();
        $this->assertDatabaseHas('qa_replies', ['id' => $replyOfB->id]);
    }
}
