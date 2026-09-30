<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\GeminiNotConfiguredException;
use App\Exceptions\AiChat\GeminiRequestFailedException;
use App\Models\AiChatConversation;
use App\Services\GeminiService;
use Illuminate\Support\Str;

/**
 * 会話の見出しを AI に付け直させる Action(S-A-02)。
 *
 * 原典 要件「会話には見出しが付き、内容に応じて AI が自動で付け直す(無効化スイッチあり)」。
 *
 * ⭐ 走るのは**初回の AI 応答が完了した直後の 1 回だけ**。根拠は支給 JS
 *    resources/js/ai-chat/chat-client.js:72 のコメント
 *    「タイトルが (初回 assistant 完了直後の) LLM 自動生成で更新されていれば通知」。
 *    毎回付け直すと、受講生が手で直したタイトル(ai-chat/show.blade.php:143 の編集モーダル)を
 *    上書きしてしまう。1 回だけなら手動編集(あとから起きる)と衝突しない。
 *
 * ⚠️ 失敗しても**握りつぶす**。見出しが仮のままでも会話は成立するので、
 *    ここの失敗で受講生の質問や応答を巻き添えにしない。
 *
 * ⚠️ これは Gemini への**追加の 1 リクエスト**になる(decisions #238)。
 *    受講生の日次上限(既定 5 通)は消費しない —— 数えるのは受講生が送ったメッセージだけで、
 *    これは受講生の送信ではないため。
 */
final class GenerateTitleAction
{
    /** タイトル生成のためだけのシステム指示。config のシステム指示は使わない(役割が違う)。 */
    private const INSTRUCTION = <<<'PROMPT'
        次のやり取りに、内容が分かる日本語の見出しを 1 つだけ付けてください。
        - 20 文字以内
        - 記号・かぎ括弧・前置きを付けず、見出しの文字列だけを返す
        PROMPT;

    public function __construct(private readonly GeminiService $gemini) {}

    /**
     * @return bool タイトルを更新したか
     */
    public function __invoke(AiChatConversation $conversation): bool
    {
        if (! config('ai-chat.auto_title')) {
            return false;
        }

        $completed = $conversation->messages()
            ->where('role', AiChatMessageRole::Assistant)
            ->where('status', AiChatMessageStatus::Completed)
            ->count();

        // 初回の応答が終わった直後だけ。2 回目以降は何もしない。
        if ($completed !== 1) {
            return false;
        }

        $firstExchange = $conversation->messages()
            ->where('status', AiChatMessageStatus::Completed)
            ->where('content', '!=', '')
            ->orderBy('created_at')
            ->limit(2)
            ->get()
            ->map(fn ($m): array => ['role' => $m->role, 'text' => $m->content])
            ->all();

        if ($firstExchange === []) {
            return false;
        }

        try {
            $result = $this->gemini->generate($firstExchange, self::INSTRUCTION, purpose: 'title');
        } catch (GeminiRequestFailedException|GeminiNotConfiguredException) {
            // 見出しが付かないだけ。会話そのものは成立している。
            return false;
        }

        $title = $this->sanitize($result['text']);

        if ($title === '') {
            return false;
        }

        $conversation->update(['title' => $title]);

        return true;
    }

    /**
     * AI の応答を title 列(varchar 100)に収まる 1 行の文字列に均す。
     *
     * ⚠️ **AI は INSTRUCTION を守らない**前提で書く。実測で 2 つ踏んだ。
     *    - 長さ: 100 文字を超えると INSERT が落ち、成功したはずのやり取りまで巻き添えになる
     *    - 記号: 「記号を付けず見出しの文字列だけを返す」と指示しても、Markdown の強調を付けて返す
     *      (2026-09-28 実測。実際の応答は `*** 2進数表現と演算の基本`)
     *
     * 前後から剥がすのは**装飾だけ**で、日本語の本文には手を付けない。
     */
    private function sanitize(string $raw): string
    {
        $oneLine = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');

        // Markdown の強調 / 見出し記号・引用符・かぎ括弧を前後から落とす。
        //
        // ⚠️ trim() は使えない。**バイト単位で削る**ため、かぎ括弧のような
        //    マルチバイト文字を指定すると日本語の末尾を途中で切って文字化けする
        //    (実測: 「二分探索の計算量」→ 二分探索の計算\xe9\x87 と壊れた)。
        //    preg_replace の u 修飾子で「文字単位」に削る。
        $pattern = '/^[\s*#`"\'<>「」『』【】［］\[\]()（）]+|[\s*#`"\'<>「」『』【】［］\[\]()（）]+$/u';
        $unwrapped = preg_replace($pattern, '', $oneLine) ?? $oneLine;

        return Str::limit($unwrapped, 60, '');
    }
}
