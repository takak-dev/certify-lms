<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Exceptions\AiChat\GeminiRequestFailedException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Services\GeminiService;
use App\UseCases\AiChat\GenerateTitleAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 会話タイトルの自動生成を検証する(S-A-02)。
 *
 * 原典 要件「会話には見出しが付き、内容に応じて AI が自動で付け直す(無効化スイッチあり)」。
 * ⭐ 走るのは**初回の AI 応答が完了した直後の 1 回だけ**(支給 JS chat-client.js:72)。
 *    2 回目以降も走ると、受講生が手で直したタイトルを上書きしてしまう。
 */
#[Group('external')]
#[Group('gemini')]
class GenerateTitleActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Gemini を差し替える。
     *
     * @param array{text: string}|\Throwable $result
     */
    private function fakeGemini(array|\Throwable $result): void
    {
        $this->mock(GeminiService::class, function ($mock) use ($result) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $expectation = $mock->shouldReceive('generate');
            $result instanceof \Throwable
                ? $expectation->andThrow($result)
                : $expectation->andReturn($result);
        });
    }

    /**
     * 「受講生の質問 + AI の応答」が 1 往復ぶんある会話を作る。
     *
     * @param int $assistantAnswers AI の完了済み応答の数(初回判定を動かすため可変にする)
     */
    private function makeConversation(int $assistantAnswers = 1): AiChatConversation
    {
        $conversation = AiChatConversation::factory()->create(['title' => '新しい相談']);
        AiChatMessage::factory()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'content' => '二分探索木の比較回数について',
        ]);
        AiChatMessage::factory()->fromAssistant()->count($assistantAnswers)->create([
            'ai_chat_conversation_id' => $conversation->id,
        ]);

        return $conversation;
    }

    public function test_replaces_the_provisional_title_after_the_first_answer(): void
    {
        // Arrange
        $conversation = $this->makeConversation();
        $this->fakeGemini(['text' => '二分探索木の比較回数']);

        // Act
        $updated = app(GenerateTitleAction::class)($conversation);

        // Assert
        $this->assertTrue($updated);
        $this->assertSame('二分探索木の比較回数', $conversation->fresh()->title);
    }

    public function test_does_not_run_again_on_later_answers(): void
    {
        // Arrange
        // ⚠️ AI の応答が 2 件目になった会話。ここで走ると、受講生が手で直した
        //    タイトルを上書きしてしまう(手動編集は 1 往復目より後に起きる)。
        $conversation = $this->makeConversation(assistantAnswers: 2);
        $conversation->update(['title' => '受講生が手で付けた名前']);
        $this->fakeGemini(['text' => 'AI が付けたい名前']);

        // Act
        $updated = app(GenerateTitleAction::class)($conversation);

        // Assert
        $this->assertFalse($updated);
        $this->assertSame('受講生が手で付けた名前', $conversation->fresh()->title);
    }

    public function test_does_nothing_when_the_switch_is_off(): void
    {
        // Arrange: 原典「無効化スイッチあり」。会話ごとではなく環境設定で切る(config/ai-chat.php)
        config(['ai-chat.auto_title' => false]);
        $conversation = $this->makeConversation();
        $this->fakeGemini(['text' => '付けたい名前']);

        // Act
        $updated = app(GenerateTitleAction::class)($conversation);

        // Assert: 仮タイトルのまま
        $this->assertFalse($updated);
        $this->assertSame('新しい相談', $conversation->fresh()->title);
    }

    public function test_failure_is_swallowed(): void
    {
        // Arrange
        // ⚠️ 見出しが付かないだけで、やり取りそのものは成立している。
        //    ここの失敗を伝播させると、成功した応答まで 502 に巻き込まれる。
        $conversation = $this->makeConversation();
        $this->fakeGemini(new GeminiRequestFailedException('Too Many Requests', 429));

        // Act
        $updated = app(GenerateTitleAction::class)($conversation);

        // Assert: 例外は外に出ず、タイトルは仮のまま
        $this->assertFalse($updated);
        $this->assertSame('新しい相談', $conversation->fresh()->title);
    }

    public function test_markdown_decorations_are_stripped(): void
    {
        // Arrange
        // ⭐ 回帰テスト。INSTRUCTION は「記号・かぎ括弧・前置きを付けず、見出しの文字列だけを返す」と
        //    指示しているが、**AI は守らないことがある**。2026-09-28 に実際の Gemini が
        //    `*** 2進数表現と演算の基本` を返し、履歴サイドバーとパンくずに記号が出た。
        //    ⚠️ テストのモックは「きれいな応答」しか返さないので、この癖は実物を叩くまで出なかった。
        $conversation = $this->makeConversation();
        $this->fakeGemini(['text' => '*** 2進数表現と演算の基本']);

        // Act
        app(GenerateTitleAction::class)($conversation);

        // Assert: 装飾だけが落ち、本文はそのまま残る
        $this->assertSame('2進数表現と演算の基本', $conversation->fresh()->title);
    }

    public function test_quotes_and_brackets_are_stripped(): void
    {
        // Arrange: かぎ括弧で包んで返してくる癖も同じ経路で落とす
        $conversation = $this->makeConversation();
        $this->fakeGemini(['text' => '「二分探索の計算量」']);

        // Act
        app(GenerateTitleAction::class)($conversation);

        // Assert: ⚠️ 本文中の記号は消さない(前後から剥がすだけ)
        $this->assertSame('二分探索の計算量', $conversation->fresh()->title);
    }

    public function test_long_or_multiline_titles_are_normalised(): void
    {
        // Arrange
        // ⚠️ AI は指示(20 文字以内)を守らないことがある。title は varchar(100) なので、
        //    詰めずに保存すると INSERT が落ち、成功したやり取りまで巻き添えになる。
        $conversation = $this->makeConversation();
        $this->fakeGemini(['text' => "改行を含む\n見出し ".str_repeat('あ', 200)]);

        // Act
        app(GenerateTitleAction::class)($conversation);

        // Assert: 1 行に均され、100 文字以内に収まる
        $title = (string) $conversation->fresh()->title;
        $this->assertStringNotContainsString("\n", $title);
        $this->assertLessThanOrEqual(100, mb_strlen($title));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
