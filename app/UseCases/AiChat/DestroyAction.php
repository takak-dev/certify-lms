<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Support\Facades\DB;

/**
 * AI 相談の会話を削除するユースケース(S-A-02)。
 *
 * ⚠️ 物理削除。AiChatConversation は SoftDeletes を使っていない。
 *    原典スコープ外に「会話の自動削除 / 一括クリーンアップ — 削除は受講生本人の手動操作のみ」とあり、
 *    復元の動線も要求されていない。誤削除の防止は支給 Blade の confirm() が担当する
 *    (resources/views/ai-chat/show.blade.php:107)。
 *
 * 発言は DB の外部キー(ai_chat_messages.ai_chat_conversation_id の cascadeOnDelete)が
 * 連鎖で消す。アプリ側で先に消して回らない。
 *
 * 手本: app/UseCases/EnrollmentGoal/DestroyAction.php。
 */
final class DestroyAction
{
    public function __invoke(AiChatConversation $conversation): void
    {
        DB::transaction(fn () => $conversation->delete());
    }
}
