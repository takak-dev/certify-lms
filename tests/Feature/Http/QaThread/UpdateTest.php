<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 質問スレッドの編集（PATCH /qa-board/{thread}）の検証。
 *
 * 原典「投稿者本人によるスレッド編集(資格は変更できない)」と、
 * 「管理者は内容の編集ができない」を固定する。解決マークの切替は ResolveTest.php。
 */
class UpdateTest extends TestCase
{
    use RefreshDatabase;

    /** 公開中の資格に紐づくスレッドを、指定した投稿者で作る */
    private function threadOf(User $author): QaThread
    {
        $certification = Certification::factory()->published()->create();

        return QaThread::factory()->forUser($author)->forCertification($certification)->create();
    }

    public function test_author_can_update_title_and_body(): void
    {
        // Arrange
        $author = User::factory()->student()->create();
        $thread = $this->threadOf($author);

        // Act
        $response = $this->actingAs($author)->patch(route('qa-board.update', $thread), [
            'title' => '編集後のタイトル',
            'body' => '編集後の本文です。',
        ]);

        // Assert
        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'title' => '編集後のタイトル',
            'body' => '編集後の本文です。',
        ]);
    }

    public function test_certification_cannot_be_changed_by_editing(): void
    {
        // Arrange: 原典「投稿者本人によるスレッド編集(資格は変更できない)」（decisions #65）。
        // 編集フォームに入力欄が無いだけでは防御にならないため、送っても効かないことを固定する
        $author = User::factory()->student()->create();
        $thread = $this->threadOf($author);
        $anotherCertification = Certification::factory()->published()->create();

        // Act
        $this->actingAs($author)->patch(route('qa-board.update', $thread), [
            'title' => '編集後のタイトル',
            'body' => '編集後の本文です。',
            'certification_id' => $anotherCertification->id,
        ]);

        // Assert: 資格は元のまま
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'certification_id' => $thread->certification_id,
        ]);
    }

    public function test_other_student_and_admin_cannot_update_thread(): void
    {
        // Arrange
        $author = User::factory()->student()->create();
        $thread = $this->threadOf($author);
        $otherStudent = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();

        $payload = ['title' => '乗っ取ったタイトル', 'body' => '書き換えた本文'];

        // Act & Assert: 他人は Policy で 403、管理者はルートのミドルウェアで 403
        $this->actingAs($otherStudent)->patch(route('qa-board.update', $thread), $payload)->assertForbidden();
        $this->actingAs($admin)->patch(route('qa-board.update', $thread), $payload)->assertForbidden();
        $this->assertDatabaseMissing('qa_threads', ['title' => '乗っ取ったタイトル']);
    }
}
