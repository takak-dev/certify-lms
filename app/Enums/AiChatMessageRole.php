<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AI 相談の発言者(S-A-02)。受講生の質問と AI の応答を 1 つのテーブルに並べるための区別。
 *
 * ⚠️ 2 ケース固定。ケース名も value も支給コードが決めている。
 *   - ケース名 : 支給 Blade resources/views/ai-chat/_partials/message-bubble.blade.php:8 が
 *                `AiChatMessageRole::User` と書いている
 *   - value    : 支給 JS resources/js/ai-chat/floating-widget.js:198,200 が
 *                `m.role === 'user'` / `m.role === 'assistant'` と**文字列で直接比較**している。
 *                JSON に出る値がこの 2 つでないと、ウィジェットの履歴復元が 1 件も描画されない
 *
 * system ロールを持たないのは、Gemini がシステム指示を会話履歴とは別の入力として受け取るため
 * (システム指示は config('ai-chat.system_prompt') から毎回渡す。DB には残さない)。
 */
enum AiChatMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
