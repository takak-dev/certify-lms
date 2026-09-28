<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AiChatMessageRole;
use App\Exceptions\AiChat\GeminiNotConfiguredException;
use App\Exceptions\AiChat\GeminiRequestFailedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI 相談(S-A-02)で Gemini とやり取りする部分をまとめて受け持つ Service。
 *
 * このクラスだけが Gemini の HTTP 仕様に触る。Controller / Action / テストは、ここが返す
 * 素の配列だけを見るようにして API の都合を外へ漏らさない
 * (手本: app/Services/StripeService.php:19 / app/Services/GoogleCalendarService.php:22)。
 *
 * ⚠️ 意図的に final にしていない。テストで Gemini への通信を差し替える必要があるため
 *    (Mockery は final クラスをモックできない)。外部 API のモックテストの本格化は T-A-04。
 *    手本の 2 つと同じ約束事(StripeService.php:23-24 / GoogleCalendarService.php:31-32)。
 *
 * ⚠️ SDK は使わない。composer.json に Gemini の SDK が無いため HTTP で直接叩く。
 *    素の Guzzle ではなく Laravel の Http ファサードを使う —— 中身は composer.json にある
 *    guzzlehttp/guzzle そのもので、加えてテストで Http::fake() が使える。
 *
 * ⚠️ API キーはクエリ文字列ではなくヘッダ(x-goog-api-key)で渡す。公式ドキュメントが
 *    どちらも認めているが、クエリに載せると URL がログ・例外メッセージ・プロキシの
 *    アクセスログに残る。リポジトリが PUBLIC なので、キーが出る経路は塞いでおく。
 *
 * 観測メタデータ(モデル名 / トークン数 / 応答時間)は呼び出し側に返さず、ここでログへ出す
 * (decisions #233)。返す値を最小限にしておけば、うっかり画面へ流れる経路が構造的に生まれない。
 */
class GeminiService
{
    /** 観測メタデータの出力先。支給済みのログチャネル(config/logging.php:132)。 */
    private const LOG_CHANNEL = 'ai-chat';

    /**
     * Gemini を使える環境か。未設定の環境では AI 相談を動かせない。
     *
     * 手本の StripeService::isConfigured() と同じ考え方。
     */
    public function isConfigured(): bool
    {
        return filled(config('services.gemini.api_key'));
    }

    /**
     * 会話を投げて応答本文を受け取る。
     *
     * @param array<int, array{role: AiChatMessageRole, text: string}> $messages
     *                                                                           直近のやり取り(古い順)。最後の要素が今回の質問。
     * @param string|null $systemPrompt システム指示(decisions #199 の ①)
     * @param string $purpose 観測ログに残す用途ラベル。会話の応答か、タイトル生成か
     *                        (decisions #238 の「1 会話につき 1 回の追加課金」を実測で切り分けるため)
     *
     * @return array{text: string}
     *
     * @throws GeminiNotConfiguredException API キーが未設定
     * @throws GeminiRequestFailedException 通信失敗 / エラー応答 / 本文が取れない応答
     */
    public function generate(array $messages, ?string $systemPrompt = null, string $purpose = 'answer'): array
    {
        if (! $this->isConfigured()) {
            throw new GeminiNotConfiguredException;
        }

        $model = (string) config('ai-chat.gemini.model');
        $url = rtrim((string) config('ai-chat.gemini.endpoint'), '/').'/models/'.$model.':generateContent';

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders(['x-goog-api-key' => (string) config('services.gemini.api_key')])
                ->timeout((int) config('ai-chat.gemini.timeout'))
                ->acceptJson()
                ->post($url, $this->buildPayload($messages, $systemPrompt));
        } catch (ConnectionException $e) {
            // 接続そのものが成立しなかった(名前解決失敗 / タイムアウト)。上流のステータスは存在しない。
            // ⚠️ 例外メッセージをそのまま使わない —— URL などが混ざる可能性があるため固定文にする。
            $this->logFailure(null, $this->elapsedMs($startedAt), purpose: $purpose);

            throw new GeminiRequestFailedException('connection failed', null, $e);
        }

        $elapsedMs = $this->elapsedMs($startedAt);

        if ($response->failed()) {
            // ⚠️ 応答ボディは記録も伝播もしない。理由の粒度は HTTP ステータスで十分で
            //    (支給 Blade もステータス番号だけを見る)、ボディには送信内容が反響して
            //    含まれることがあるため。
            $this->logFailure($response->status(), $elapsedMs, purpose: $purpose);

            throw new GeminiRequestFailedException((string) $response->reason(), $response->status());
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text) || $text === '') {
            // 200 だが本文が無い。安全フィルタで止まった場合などにここへ来る。
            $finishReason = $response->json('candidates.0.finishReason');
            $this->logFailure(null, $elapsedMs, is_string($finishReason) ? $finishReason : 'empty response', $purpose);

            throw new GeminiRequestFailedException('empty response');
        }

        // 成功時の観測記録。⚠️ プロンプト本文は出さない —— 受講生の相談内容そのものであり、
        //    運用観測に必要でもない(原典「運用観測メタデータを内部記録として残す」)。
        Log::channel(self::LOG_CHANNEL)->info('gemini.succeeded', [
            'model' => $response->json('modelVersion') ?? $model,
            'response_time_ms' => $elapsedMs,
            'prompt_tokens' => $response->json('usageMetadata.promptTokenCount'),
            'output_tokens' => $response->json('usageMetadata.candidatesTokenCount'),
            'finish_reason' => $response->json('candidates.0.finishReason'),
            'history_count' => count($messages),
            'purpose' => $purpose,
        ]);

        return ['text' => $text];
    }

    /**
     * Gemini の generateContent が受け取る形に組み立てる。
     *
     * ⚠️ ロール名の読み替えがここの肝。Gemini 側の role は "user" と **"model"** の 2 つで、
     *    こちらの AiChatMessageRole::Assistant("assistant")をそのまま送ると弾かれる。
     *    この読み替えを Service の外に出さないのが「API の都合を漏らさない」ということ。
     *
     * @param array<int, array{role: AiChatMessageRole, text: string}> $messages
     *
     * @return array<string, mixed>
     */
    private function buildPayload(array $messages, ?string $systemPrompt): array
    {
        $payload = [
            'contents' => array_map(
                fn (array $m): array => [
                    'role' => $m['role'] === AiChatMessageRole::Assistant ? 'model' : 'user',
                    'parts' => [['text' => $m['text']]],
                ],
                $messages,
            ),
        ];

        if (filled($systemPrompt)) {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        return $payload;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function logFailure(?int $status, int $elapsedMs, ?string $note = null, string $purpose = 'answer'): void
    {
        Log::channel(self::LOG_CHANNEL)->warning('gemini.failed', [
            'upstream_status' => $status,
            'response_time_ms' => $elapsedMs,
            'note' => $note,
            'purpose' => $purpose,
        ]);
    }
}
