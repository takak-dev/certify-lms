<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\AiChatMessageRole;
use App\Exceptions\AiChat\GeminiNotConfiguredException;
use App\Exceptions\AiChat\GeminiRequestFailedException;
use App\Services\GeminiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * GeminiService が「Gemini の都合」をどこまで内側に閉じ込めているかを検証する(S-A-02)。
 *
 * ⚠️ 実通信はしない。Http::fake() で応答を固定する。
 *    phpunit.xml が GEMINI_API_KEY を空に固定しているので、モックを付け忘れたテストは
 *    通信する前に GeminiNotConfiguredException で落ちる(事故の二重の歯止め)。
 *
 * ここで守りたいのは 3 つ。
 *  ① 送信の形 —— ロール名の読み替え(assistant → model)と、API キーをヘッダで送ること
 *  ② 失敗の形 —— 上流の HTTP ステータスを持った例外にすること(支給コードが番号で文言を変える)
 *  ③ 返す値の形 —— 観測メタデータを**返さない**こと(decisions #233)
 */
class GeminiServiceTest extends TestCase
{
    /** テスト用のダミーキー。本物は使わない。 */
    private const FAKE_KEY = 'test-api-key-not-real';

    private function configure(): void
    {
        config(['services.gemini.api_key' => self::FAKE_KEY]);
        config(['ai-chat.gemini.model' => 'gemini-3.8-flash']);
        config(['ai-chat.gemini.endpoint' => 'https://generativelanguage.googleapis.com/v1beta']);
    }

    /**
     * Gemini が成功したときの応答(公式 REST の形)。
     *
     * @return array<string, mixed>
     */
    private function successBody(string $text = 'こう考えると理解しやすいです。'): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [['text' => $text]], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 120,
                'candidatesTokenCount' => 48,
                'totalTokenCount' => 168,
            ],
            'modelVersion' => 'gemini-3.8-flash',
        ];
    }

    public function test_is_not_configured_when_api_key_is_empty(): void
    {
        // Arrange: phpunit.xml が空に固定している既定の状態
        $service = new GeminiService;

        // Assert
        $this->assertFalse($service->isConfigured());
    }

    public function test_generate_throws_when_api_key_is_missing(): void
    {
        // Arrange: キー未設定のまま呼ぶ
        Http::fake();
        $service = new GeminiService;

        // Act
        // ⚠️ expectException() を使わない —— 例外でメソッドが抜けると、その後ろの
        //    assertNothingSent() が一度も実行されない(「通信していない」を機械で守れなくなる)。
        try {
            $service->generate([['role' => AiChatMessageRole::User, 'text' => '質問']]);
            $this->fail('例外が投げられていない');
        } catch (GeminiNotConfiguredException) {
            // 原典「API キーが未設定の環境では、利用できない旨を案内する」の入口
        }

        // Assert: 通信は 1 度も起きない
        Http::assertNothingSent();
    }

    public function test_generate_returns_only_the_text(): void
    {
        // Arrange
        $this->configure();
        Http::fake(['*' => Http::response($this->successBody('答えの本文'))]);

        // Act
        $result = (new GeminiService)->generate(
            [['role' => AiChatMessageRole::User, 'text' => '質問']],
            'システム指示',
        );

        // Assert: text だけ。観測メタデータ(トークン数・応答時間・モデル名)は返さない。
        //         返さないことが decisions #233 の構造的な担保になっている。
        $this->assertSame(['text' => '答えの本文'], $result);
    }

    public function test_observability_is_recorded_to_the_ai_chat_log_channel(): void
    {
        // Arrange
        // ⭐ 原典の非機能要件は 2 つで 1 組 ——「観測メタデータを**内部記録として残す**」かつ
        //    「受講生には表示しない」。表示しない側は ConversationViewTest が見張っているが、
        //    **記録する側の見張りが無かった**(3 周目のレビューで指摘)。
        //    出力先は支給済みのログチャネル(config/logging.php:132。decisions #233)。
        $this->configure();
        Http::fake(['*' => Http::response($this->successBody())]);

        Log::shouldReceive('channel')->with('ai-chat')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('gemini.succeeded', $message);
            $this->assertSame('gemini-3.8-flash', $context['model']);
            $this->assertSame(48, $context['output_tokens']);
            $this->assertSame(120, $context['prompt_tokens']);
            $this->assertIsInt($context['response_time_ms']);
            // 会話の応答か、タイトル生成か(decisions #238 の追加 1 リクエストを切り分けるため)
            $this->assertSame('answer', $context['purpose']);

            // ⚠️ 相談内容そのものは 1 文字も出さない。受講生の質問はログに残す情報ではない。
            $this->assertSame([], array_intersect(
                ['prompt', 'messages', 'contents', 'content', 'text', 'system_prompt'],
                array_keys($context),
            ));

            return true;
        });

        // Act
        (new GeminiService)->generate(
            [['role' => AiChatMessageRole::User, 'text' => 'ログニノコッテハイケナイ質問']],
            'システム指示',
        );
    }

    public function test_request_uses_header_auth_and_maps_assistant_role_to_model(): void
    {
        // Arrange: 受講生 → AI → 受講生 の 3 通を渡す
        $this->configure();
        Http::fake(['*' => Http::response($this->successBody())]);

        // Act
        (new GeminiService)->generate([
            ['role' => AiChatMessageRole::User, 'text' => '1 通目'],
            ['role' => AiChatMessageRole::Assistant, 'text' => '2 通目'],
            ['role' => AiChatMessageRole::User, 'text' => '3 通目'],
        ], 'システム指示');

        // Assert
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            // ⚠️ キーは URL に載せない。リポジトリが PUBLIC なので、
            //    URL がログに残る経路を塞いでいることを機械で見張る。
            $this->assertStringNotContainsString(self::FAKE_KEY, $request->url());
            $this->assertSame(self::FAKE_KEY, $request->header('x-goog-api-key')[0]);
            $this->assertStringEndsWith('/models/gemini-3.8-flash:generateContent', $request->url());

            // ⚠️ ここが読み替えの肝。Gemini 側の role は user と model の 2 つで、
            //    assistant のまま送ると弾かれる。
            $this->assertSame('user', $body['contents'][0]['role']);
            $this->assertSame('model', $body['contents'][1]['role']);
            $this->assertSame('user', $body['contents'][2]['role']);
            $this->assertSame('3 通目', $body['contents'][2]['parts'][0]['text']);

            // システム指示は contents ではなく専用のフィールドで渡す
            $this->assertSame('システム指示', $body['systemInstruction']['parts'][0]['text']);

            return true;
        });
    }

    public function test_system_instruction_is_omitted_when_empty(): void
    {
        // Arrange: 機能 OFF などでシステム指示が空になった場合
        $this->configure();
        Http::fake(['*' => Http::response($this->successBody())]);

        // Act
        (new GeminiService)->generate([['role' => AiChatMessageRole::User, 'text' => '質問']], null);

        // Assert: 空のフィールドを送らない(API 側で弾かれるため)
        Http::assertSent(fn (Request $request): bool => ! array_key_exists('systemInstruction', $request->data()));
    }

    public function test_upstream_error_status_is_carried_on_the_exception(): void
    {
        // Arrange: Gemini 側のレート制限
        $this->configure();
        Http::fake(['*' => Http::response(['error' => ['message' => 'quota']], 429)]);

        // Act
        try {
            (new GeminiService)->generate([['role' => AiChatMessageRole::User, 'text' => '質問']]);
            $this->fail('例外が投げられていない');
        } catch (GeminiRequestFailedException $e) {
            // Assert: 支給 JS chat-client.js:57-61 が読む upstream_status の素になる
            $this->assertSame(429, $e->upstreamStatus);
            // ⚠️ error_detail に番号が入ること。支給 Blade message-bubble.blade.php:55-56 が
            //    この文字列から 429 を探して受講生向けの文言に置き換える。
            $this->assertStringContainsString('429', $e->detail());
        }

        // 原典 スコープ外「リトライはしない(失敗分も日次カウント)」—— 1 回だけ送る
        Http::assertSentCount(1);
    }

    public function test_empty_response_is_treated_as_failure(): void
    {
        // Arrange: HTTP は 200 だが本文が無い(安全フィルタで止まった場合など)
        $this->configure();
        Http::fake(['*' => Http::response([
            'candidates' => [['finishReason' => 'SAFETY']],
        ])]);

        // Act
        try {
            (new GeminiService)->generate([['role' => AiChatMessageRole::User, 'text' => '質問']]);
            $this->fail('例外が投げられていない');
        } catch (GeminiRequestFailedException $e) {
            // Assert: 上流のステータスは 200 なので「番号なし」の失敗として扱う
            $this->assertNull($e->upstreamStatus);
        }
    }

    public function test_connection_failure_is_wrapped(): void
    {
        // Arrange: 名前解決失敗やタイムアウト。HTTP のやり取りが成立していない
        $this->configure();
        Http::fake(fn () => throw new ConnectionException('cURL error 28'));

        // Act
        try {
            (new GeminiService)->generate([['role' => AiChatMessageRole::User, 'text' => '質問']]);
            $this->fail('例外が投げられていない');
        } catch (GeminiRequestFailedException $e) {
            // Assert: 上流のステータスは存在しない
            $this->assertNull($e->upstreamStatus);
            // ⚠️ cURL の生メッセージを受講生向けの経路に流さない
            $this->assertStringNotContainsString('cURL', $e->detail());
        }
    }
}
