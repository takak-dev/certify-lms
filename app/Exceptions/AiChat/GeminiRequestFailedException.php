<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gemini への問い合わせが失敗したことを表す例外(S-A-02・HTTP 502)。
 * `GeminiService` が通信エラー・エラー応答・空の応答をまとめて包み替えて throw する。
 *
 * ⚠️ 502 の専用クラスは Symfony に無いため、HttpException にステータスを直接渡している
 *    (vendor/symfony/http-kernel/Exception/ に BadGatewayHttpException は存在しない。実測)。
 *
 * ⚠️ この例外は Handler に届く前に AiChatAction 側で 1 度捕まえられ、応答の行が Error として
 *    保存されてから投げ直される(StoreMessageAction)。原典「AI 応答に失敗しても受講生の質問は残り、
 *    同じ内容を送り直して再質問できる」を満たすのはその保存であって、この例外の親クラスではない。
 *
 * ⭐ upstreamStatus を持つのは、支給コードが番号で挙動を変えるため。
 *   - 支給 JS resources/js/ai-chat/chat-client.js:57-61 … 502 応答の `upstream_status` を読む
 *   - 支給 Blade resources/views/ai-chat/_partials/message-bubble.blade.php:55-56
 *     … error_detail の文字列から 429 / 503 を探して受講生向けの文言を出し分ける
 */
final class GeminiRequestFailedException extends HttpException
{
    /**
     * @param int|null $upstreamStatus Gemini が返した HTTP ステータス。通信自体が成立しなければ null
     */
    public function __construct(
        string $message,
        public readonly ?int $upstreamStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(502, $message, $previous);
    }

    /**
     * 支給 JS が読む形の 502 応答を自分で組み立てる。
     *
     * Handler の既定では body が `{"message": ...}` だけになり、
     * 支給 JS resources/js/ai-chat/chat-client.js:57-61 が読む `upstream_status` が落ちる。
     * ⚠️ app/Exceptions/Handler.php:41-42 が render() に触れているのは**403 で redirect したい場合**に
     *    限った記述で、この用途を直接許しているわけではない。render() 自体は Laravel 標準の機構
     *    (Handler が method_exists で拾う)で、リポジトリ内で使うのはここが初。
     *
     * ⚠️ 受講生に見せるのは固定文。getMessage() は Gemini の理由(英語)なのでそのまま出さない。
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => 'AI が応答できませんでした。',
            'upstream_status' => $this->upstreamStatus,
        ], 502);
    }

    /**
     * ai_chat_messages.error_detail に保存する文字列。
     *
     * ⚠️ 受講生にそのまま見せる文言ではない(支給 Blade がこの文字列から番号を読み取り、
     *    自前の日本語文言に置き換える)。API キーなどの秘密が混ざらないよう、
     *    GeminiService は例外メッセージに応答ボディを入れない。
     */
    public function detail(): string
    {
        return $this->upstreamStatus !== null
            ? sprintf('Gemini API error: HTTP %d %s', $this->upstreamStatus, $this->getMessage())
            : sprintf('Gemini API error: %s', $this->getMessage());
    }
}
