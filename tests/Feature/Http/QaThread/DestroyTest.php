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
 * 質問スレッドの削除（DELETE /qa-board/{thread}、DELETE /admin/qa-board/{thread}）の検証。
 *
 * 面談1で確定した3点を固定する（decisions #37）:
 * 投稿者本人は回答が1件でもあれば 409 / 管理者は無条件で削除可 / 配下の回答も連動削除。
 * 手本: tests/Feature/Http/CertificationCategory/DestroyTest.php（成功と 409 の 2 本立て）。
 */
class DestroyTest extends TestCase
{
    use RefreshDatabase;

    private function threadOf(User $author): QaThread
    {
        $certification = Certification::factory()->published()->create();

        return QaThread::factory()->forUser($author)->forCertification($certification)->create();
    }

    public function test_author_can_delete_thread_without_replies(): void
    {
        // Arrange: 回答が付いていないスレッド
        $author = User::factory()->student()->create();
        $thread = $this->threadOf($author);

        // Act
        $response = $this->actingAs($author)->delete(route('qa-board.destroy', $thread));

        // Assert: 一覧へ戻る（消えた本人の画面には戻れないため。_共通ルール.md §1）
        $response->assertRedirect(route('qa-board.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
    }

    public function test_author_cannot_delete_thread_that_has_replies(): void
    {
        // Arrange: 回答を1件付ける。他の受講生の回答まで消えて集合知が失われるため拒否する
        $author = User::factory()->student()->create();
        $thread = $this->threadOf($author);
        QaReply::factory()->forThread($thread)->fromCoach()->create();

        // Act: JSON 経路で投げるとステータスコードが直接返る（_共通ルール.md §2 の使い分け）
        $response = $this->actingAs($author)->deleteJson(route('qa-board.destroy', $thread));

        // Assert
        $response->assertStatus(409);
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
    }

    public function test_conflict_is_shown_as_error_flash_on_html_request(): void
    {
        // Arrange: 画面から削除ボタンを押した場合は、直前ページに戻って理由が表示される
        $author = User::factory()->student()->create();
        $thread = $this->threadOf($author);
        QaReply::factory()->forThread($thread)->fromCoach()->create();

        // Act
        $response = $this->actingAs($author)->delete(route('qa-board.destroy', $thread));

        // Assert: Handler が 409 をリダイレクト + error フラッシュに変換する
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
    }

    public function test_admin_can_delete_thread_with_replies_and_replies_are_removed(): void
    {
        // Arrange: 管理者のモデレーション削除は条件を受けない。配下の回答も消える
        $author = User::factory()->student()->create();
        $thread = $this->threadOf($author);
        $reply = QaReply::factory()->forThread($thread)->fromCoach()->create();
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->delete(route('admin.qa-board.destroy', $thread));

        // Assert: モデレーション画面の一覧へ戻る。回答は外部キーの cascade で連動削除される
        $response->assertRedirect(route('admin.qa-board.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
    }

    public function test_other_student_cannot_delete_thread(): void
    {
        // Arrange
        $author = User::factory()->student()->create();
        $thread = $this->threadOf($author);
        $otherStudent = User::factory()->student()->create();

        // Act & Assert
        $this->actingAs($otherStudent)->delete(route('qa-board.destroy', $thread))->assertForbidden();
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
    }

    public function test_coach_cannot_delete_thread(): void
    {
        // Arrange: コーチは閲覧と回答のみ。削除の権限は持たない
        $author = User::factory()->student()->create();
        $thread = $this->threadOf($author);
        $coach = User::factory()->coach()->create();

        // Act & Assert
        $this->actingAs($coach)->delete(route('qa-board.destroy', $thread))->assertForbidden();
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
    }
}
