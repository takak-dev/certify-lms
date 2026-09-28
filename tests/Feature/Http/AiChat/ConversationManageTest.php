<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 会話タイトルの変更と会話の削除を検証する(S-A-02)。
 *
 * どちらも支給 Blade resources/views/ai-chat/show.blade.php の
 * モーダル(:143)とフォーム(:106)から素のフォーム POST で届く(JSON では来ない)。
 *
 * ⚠️ 操作できるのはオーナー本人だけ。**管理者にも許可しない**
 *    (原典スコープ外「他受講生の会話履歴閲覧(管理者 / コーチ含む)」)。
 */
class ConversationManageTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_rename_the_conversation(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create([
            'user_id' => $owner->id,
            'title' => '新しい相談',
        ]);

        // Act
        $response = $this->actingAs($owner)
            ->patch(route('ai-chat.conversations.update', $conversation), ['title' => '二分探索木のメモ']);

        // Assert: 会話へ戻り、タイトルが変わっている
        $response->assertRedirect(route('ai-chat.conversations.show', $conversation));
        // ⚠️ キーは必ず success(_共通ルール.md:39-40。B-B-07 で実際に踏んだバグ)
        $response->assertSessionHas('success');
        $this->assertSame('二分探索木のメモ', $conversation->fresh()->title);
    }

    public function test_title_is_required_and_capped_at_100_characters(): void
    {
        // Arrange
        // 上限は支給 Blade の入力欄に合わせてある(resources/views/ai-chat/show.blade.php:152 の :maxlength="100")。
        // maxlength は画面の制限でしかないので、サーバ側でも弾けることを確かめる。
        $owner = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);

        // Act & Assert: 空は弾く
        $this->actingAs($owner)
            ->patch(route('ai-chat.conversations.update', $conversation), ['title' => ''])
            ->assertSessionHasErrors('title');

        // Act & Assert: 101 文字は弾く
        $this->actingAs($owner)
            ->patch(route('ai-chat.conversations.update', $conversation), ['title' => str_repeat('あ', 101)])
            ->assertSessionHasErrors('title');
    }

    public function test_another_student_cannot_rename(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create([
            'user_id' => $owner->id,
            'title' => '元のまま',
        ]);

        // Act
        $response = $this->actingAs($other)
            ->patch(route('ai-chat.conversations.update', $conversation), ['title' => '乗っ取り']);

        // Assert
        $response->assertForbidden();
        $this->assertSame('元のまま', $conversation->fresh()->title);
    }

    public function test_owner_can_delete_the_conversation_with_its_messages(): void
    {
        // Arrange: 発言を 2 件ぶら下げた会話
        $owner = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);
        AiChatMessage::factory()->count(2)->create(['ai_chat_conversation_id' => $conversation->id]);

        // Act
        $response = $this->actingAs($owner)
            ->delete(route('ai-chat.conversations.destroy', $conversation));

        // Assert: 入口へ戻る
        $response->assertRedirect(route('ai-chat.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('ai_chat_conversations', ['id' => $conversation->id]);
        // 発言は DB の外部キー(cascadeOnDelete)が連鎖で消す。アプリ側で消して回っていない
        $this->assertSame(0, AiChatMessage::count());
    }

    public function test_admin_cannot_delete_a_students_conversation(): void
    {
        // Arrange
        // ⚠️ QaThreadPolicy::delete() は管理者にモデレーション権限を与えているが、
        //    AI 相談は原典スコープ外により管理者にも触らせない。流用すると原典違反になる。
        $admin = User::factory()->admin()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create();

        // Act
        $response = $this->actingAs($admin)
            ->delete(route('ai-chat.conversations.destroy', $conversation));

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('ai_chat_conversations', ['id' => $conversation->id]);
    }
}
