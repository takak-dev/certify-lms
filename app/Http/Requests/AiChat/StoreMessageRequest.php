<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * AI 相談にメッセージを送るときの入力検証(S-A-02)。
 *
 * 送ってくるのは支給 JS だけ(resources/js/ai-chat/chat-client.js:45 が
 * `JSON.stringify({ content })` を投げる)。
 *
 * ⚠️ 上限 2000 は支給画面の入力欄に合わせる
 *    (resources/views/ai-chat/_partials/input-form.blade.php:19 の maxlength="2000")。
 *    支給 JS も 422 のときだけ「入力内容を確認してください (1-2000 文字)。」と出す
 *    (resources/js/ai-chat/floating-widget.js:167)ので、文字数違反は必ず 422 で返す必要がある。
 *
 * 認可は Policy に委ねる。会話オーナー以外は 403(AiChatConversationPolicy::createMessage)。
 */
class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof AiChatConversation
            && $this->user()?->can('createMessage', $conversation) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * 項目名の日本語表記(lang/ja/validation.php:124-127)。
     *
     * ⚠️ この 422 の本文は受講生に見えない —— 支給 JS はボディを読まず固定文を出す
     *    (resources/js/ai-chat/chat-client.js:52-55 → floating-widget.js:167)。
     *    それでも付けるのは、JSON 専用の FormRequest 3 本のうち 2 本が持っているため
     *    (MockExamAnswer/UpdateRequest / SectionQuestionAnswer/StoreRequest。2026-09-28 実測)。
     *    持たないのは Chat/StoreMessageRequest の 1 本だけで、少数側に揃える理由が無い。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'content' => 'メッセージ',
        ];
    }
}
