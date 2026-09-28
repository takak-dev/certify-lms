<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\GeminiNotConfiguredException;
use App\Exceptions\AiChat\GeminiRequestFailedException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Services\GeminiService;
use App\UseCases\AiChat\StoreMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * メッセージ送信の Action を検証する(S-A-02)。
 *
 * ⭐ 一番の目的は原典「**AI 応答に失敗しても受講生の質問は残り、同じ内容を送り直して
 *    再質問できる**」の担保。ここを壊す変更(Gemini の呼び出しをトランザクションに入れる等)を
 *    機械で止める。
 *
 * ⚠️ Gemini は差し替える。GeminiService を final にしていないのはこのため
 *    (Mockery は final クラスをモックできない)。実通信は 1 度も起きない。
 */
#[Group('external')]
#[Group('gemini')]
class StoreMessageActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Gemini を差し替えた Action を組み立てる。
     *
     * @param array{text: string}|\Throwable $result 返す値、または投げる例外
     */
    private function makeAction(array|\Throwable $result, bool $configured = true): StoreMessageAction
    {
        $this->mock(GeminiService::class, function ($mock) use ($result, $configured) {
            $mock->shouldReceive('isConfigured')->andReturn($configured);

            $expectation = $mock->shouldReceive('generate');
            $result instanceof \Throwable
                ? $expectation->andThrow($result)
                : $expectation->andReturn($result);
        });

        return app(StoreMessageAction::class);
    }

    public function test_saves_the_question_and_the_answer(): void
    {
        // Arrange
        $conversation = AiChatConversation::factory()->create();
        $action = $this->makeAction(['text' => 'AI の回答です。']);

        // Act
        $result = $action($conversation, '二分探索木について教えて');

        // Assert: 受講生の発言は最初から completed、AI の応答は本文つきで completed
        $this->assertSame(AiChatMessageRole::User, $result['user']->role);
        $this->assertSame(AiChatMessageStatus::Completed, $result['user']->status);
        $this->assertSame('二分探索木について教えて', $result['user']->content);

        $this->assertSame(AiChatMessageRole::Assistant, $result['assistant']->role);
        $this->assertSame(AiChatMessageStatus::Completed, $result['assistant']->status);
        $this->assertSame('AI の回答です。', $result['assistant']->content);

        // 親の last_message_at が更新されている(履歴サイドバーの並び順の素)
        $this->assertNotNull($conversation->fresh()->last_message_at);
    }

    public function test_question_survives_when_the_ai_fails(): void
    {
        // Arrange
        // ⭐ この 1 本が原典の要求そのもの。Gemini が 503 を返す状況を作る。
        $conversation = AiChatConversation::factory()->create();
        $action = $this->makeAction(new GeminiRequestFailedException('Service Unavailable', 503));

        // Act: 例外は呼び出し側へ抜ける(Controller が 502 に変換する)
        try {
            $action($conversation, '消えては困る質問');
            $this->fail('例外が投げられていない');
        } catch (GeminiRequestFailedException $e) {
            $this->assertSame(503, $e->upstreamStatus);
        }

        // Assert: 受講生の質問は DB に残っている(= 同じ内容を送り直せる)
        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User->value,
            'content' => '消えては困る質問',
            'status' => AiChatMessageStatus::Completed->value,
        ]);

        // Assert: AI の応答はエラーとして残り、error_detail に番号が入っている
        //         (支給 Blade message-bubble.blade.php:55-56 がこの番号で文言を出し分ける)
        $assistant = AiChatMessage::query()
            ->where('ai_chat_conversation_id', $conversation->id)
            ->where('role', AiChatMessageRole::Assistant)
            ->sole();
        $this->assertSame(AiChatMessageStatus::Error, $assistant->status);
        $this->assertStringContainsString('503', (string) $assistant->error_detail);
        $this->assertSame('', $assistant->content);
    }

    public function test_the_same_question_can_be_sent_again_after_a_failure(): void
    {
        // Arrange: 1 回目は失敗
        $conversation = AiChatConversation::factory()->create();
        try {
            $this->makeAction(new GeminiRequestFailedException('boom', 500))($conversation, '同じ質問');
        } catch (GeminiRequestFailedException) {
            // 失敗は想定どおり
        }

        // Act: 同じ内容をもう一度送る(2 回目は成功する)
        $this->makeAction(['text' => '今度は答えられました。'])($conversation, '同じ質問');

        // Assert: 質問 2 件 + 応答 2 件(失敗した応答も履歴に残る = 原典の初期データ要求と同じ状態)
        $this->assertSame(2, AiChatMessage::where('role', AiChatMessageRole::User)->count());
        $this->assertSame(1, AiChatMessage::where('status', AiChatMessageStatus::Error)->count());
        $this->assertSame(3, AiChatMessage::where('status', AiChatMessageStatus::Completed)->count());
    }

    public function test_nothing_is_saved_when_the_api_key_is_missing(): void
    {
        // Arrange: 環境の不備。何度送り直しても直らないので履歴を汚さない
        $conversation = AiChatConversation::factory()->create();
        $action = $this->makeAction(['text' => '届かない'], configured: false);

        // Assert
        $this->expectException(GeminiNotConfiguredException::class);

        // Act
        try {
            $action($conversation, '質問');
        } finally {
            // 行が 1 つも作られていないこと
            $this->assertSame(0, AiChatMessage::count());
        }
    }

    public function test_the_new_question_is_not_sent_twice(): void
    {
        // Arrange
        // ⚠️ 受講生の発言を先に保存してから入力を組み立てると、同じ文が
        //    「履歴」と「今回の質問」の両方に入り、2 回 Gemini に届く。
        //    組み立て → 保存 の順になっていることを、実際に渡された入力で確かめる。
        $conversation = AiChatConversation::factory()->create();
        $sent = null;

        $this->mock(GeminiService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('generate')
                ->andReturnUsing(function (array $messages) use (&$sent) {
                    $sent = $messages;

                    return ['text' => '回答'];
                });
        });

        // Act
        app(StoreMessageAction::class)($conversation, '一度だけ届くべき質問');

        // Assert: 渡された入力に同じ質問は 1 回しか現れない
        $texts = array_column($sent, 'text');
        $this->assertSame(1, count(array_filter($texts, fn ($t) => $t === '一度だけ届くべき質問')));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
