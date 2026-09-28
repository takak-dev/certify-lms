<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;

/**
 * AI 相談の会話 1 件を、画面に必要な関連ごと読み込む Action。
 *
 * 手本: app/UseCases/Chat/ShowAction.php(同じ「1 スレッドとその発言を読み込む」処理)。
 * あちらと違い既読の更新は無い —— AI 相談は 1 人の会話なので未読という概念が無い。
 */
final class ShowAction
{
    public function __invoke(AiChatConversation $conversation): AiChatConversation
    {
        $conversation->load([
            // 会話ヘッダのコンテキストバッジ(📚 / 🎓 / 全般相談)が参照する
            // (resources/views/ai-chat/_partials/context-badges.blade.php:8,11)。
            'section',
            'enrollment.certification',

            // ⚠️ 並び順はここで指定する。支給 Blade
            //    resources/views/ai-chat/_partials/message-list.blade.php:15 は
            //    渡されたコレクションをそのまま回すだけで、並べ替えをしない。
            'messages' => fn ($q) => $q->orderBy('created_at'),
        ]);

        return $conversation;
    }
}
