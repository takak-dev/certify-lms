<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Support\Facades\DB;

/**
 * 会話タイトルを受講生が手で付け替えるユースケース(S-A-02)。
 *
 * ⚠️ AI による自動生成(GenerateTitleAction)とは経路が別。
 *    自動生成は初回の応答直後 1 回だけなので、手動編集(それより後に起きる)と衝突しない。
 *
 * 手本: app/UseCases/EnrollmentGoal/UpdateAction.php。
 */
final class UpdateTitleAction
{
    /**
     * @param array{title: string} $validated AiChat/UpdateConversationRequest::rules() で検証済
     */
    public function __invoke(AiChatConversation $conversation, array $validated): AiChatConversation
    {
        return DB::transaction(function () use ($conversation, $validated): AiChatConversation {
            $conversation->update($validated);

            return $conversation->fresh();
        });
    }
}
