<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AI 相談の発言の状態(S-A-02)。実質は「AI の応答が返ってきたか」を表す。
 *
 * - [*] → Pending   : 応答の行を作った直後。Gemini の返事をまだ待っている
 * - Pending → Completed : Gemini が応答を返し、本文を保存できた
 * - Pending → Error     : Gemini が失敗した。本文は空のまま error_detail に理由を残す
 *
 * ⭐ 受講生の発言は最初から Completed で作る。原典「AI 応答に失敗しても受講生の質問は残り、
 *    同じ内容を送り直して再質問できる」を満たすため、質問の保存と Gemini の呼び出しを
 *    同じトランザクションに入れない。処理が途中で落ちても質問は残り、応答は Pending のまま残る。
 *
 * ⚠️ 3 ケース固定。ケース名も value も支給コードが決めている。
 *   - ケース名 : 支給 Blade resources/views/ai-chat/_partials/message-bubble.blade.php:9,10,73 が
 *                `AiChatMessageStatus::Error` / `::Pending` / `::Completed` と書いている
 *   - value    : 支給 JS resources/js/ai-chat/message-renderer.js:46,87 が
 *                `message.status === 'error'` / `=== 'completed'` と文字列で直接比較している。
 *                支給 Blade :17 も `$message->status->value` を DOM 属性に焼いており、
 *                **backed enum(値つき Enum)でないと `->value` が存在せず画面が落ちる**
 *
 * ⚠️ label() を持たないのは、支給画面がこの Enum の日本語表示を一切要求していないため
 *    (PaymentStatus が label() を持つのは meeting-pack 側の Blade が呼んでいるから)。
 */
enum AiChatMessageStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Error = 'error';
}
