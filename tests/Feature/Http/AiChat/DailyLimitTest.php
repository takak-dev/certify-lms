<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Enums\AiChatMessageRole;
use App\Exceptions\AiChat\GeminiRequestFailedException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * 受講生 1 人あたりの 1 日の送信上限を検証する(S-A-02)。
 *
 * 原典 非機能要件「受講生 1 人あたりの 1 日の送信回数に上限を設ける」。値は 20 通(decisions #234)。
 *
 * ⭐ 数えるのは**受講生が送った行(role = user)だけ**。これにより原典スコープ外
 *    「AI 失敗時の Rate Limit クォータ補正(**失敗分も日次カウント**)」が自動的に満たされる。
 *
 * ⚠️ 応答は必ず **429**。支給 JS resources/js/ai-chat/chat-client.js:48-51 が 429 のときだけ
 *    「本日の利用上限に達しました。明日 0:00 以降に再度ご利用ください。」を出す。
 *
 * テストでは上限を小さくして回数を現実的に保つ(値そのものではなく**仕組み**を確かめたいため)。
 */
class DailyLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
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
     * 既に送信済みの状態を作る。
     * ⚠️ Factory の既定のまま作る —— 既定が role=user / status=completed で、
     *    ちょうど数える対象そのものだから。
     */
    private function seedSentMessages(AiChatConversation $conversation, int $count): void
    {
        AiChatMessage::factory()->count($count)->create([
            'ai_chat_conversation_id' => $conversation->id,
        ]);
    }

    public function test_the_limit_is_the_configured_value(): void
    {
        // Arrange: 上限 3 通に対して 2 通だけ送信済み(まだ 1 通送れる)
        config(['ai-chat.daily_message_limit' => 3]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $this->seedSentMessages($conversation, 2);
        $this->fakeGemini(['text' => 'AI の回答']);

        // Act
        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '3 通目'],
        );

        // Assert: ちょうど上限に達する 1 通は通る
        $response->assertOk();
    }

    public function test_exceeding_the_limit_returns_429_and_saves_nothing(): void
    {
        // Arrange: 上限 3 通に対して 3 通送信済み
        config(['ai-chat.daily_message_limit' => 3]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $this->seedSentMessages($conversation, 3);
        $this->fakeGemini(['text' => '届かない']);

        // Act
        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '4 通目'],
        );

        // Assert: 429。Gemini を呼ぶ前に弾くので、行も増えない
        $response->assertStatus(429);
        $this->assertSame(3, AiChatMessage::count());
    }

    public function test_a_failed_answer_still_consumes_the_quota(): void
    {
        // Arrange
        // ⭐ 原典スコープ外「AI 失敗時の Rate Limit クォータ補正(失敗分も日次カウント)」。
        //    AI が失敗しても、受講生の送信としては 1 通消費する。
        config(['ai-chat.daily_message_limit' => 1]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $this->fakeGemini(new GeminiRequestFailedException('Service Unavailable', 503));

        // Act: 1 通目は AI が失敗する(502 が返る)
        $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '失敗する質問'],
        )->assertStatus(502);

        // Act: 2 通目
        $this->fakeGemini(['text' => '今度は成功']);
        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '2 通目'],
        );

        // Assert: 失敗した 1 通目も消費しているので、2 通目は上限超過
        $response->assertStatus(429);
    }

    public function test_the_count_resets_on_the_next_calendar_day(): void
    {
        // Arrange
        // ⚠️ 暦日で区切る。支給 JS の文言が「明日 0:00 以降に再度ご利用ください」なので、
        //    24 時間のスライド窓にしない。昨日 23:59 の送信は今日の数に入らない。
        config(['ai-chat.daily_message_limit' => 1]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        AiChatMessage::factory()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'created_at' => today()->subDay()->setTime(23, 59),
        ]);
        $this->fakeGemini(['text' => 'AI の回答']);

        // Act
        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '今日の 1 通目'],
        );

        // Assert
        $response->assertOk();
    }

    public function test_another_students_messages_do_not_count(): void
    {
        // Arrange: 上限は「受講生 1 人あたり」。他人の送信に巻き込まれない
        config(['ai-chat.daily_message_limit' => 1]);
        $student = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $this->seedSentMessages(
            AiChatConversation::factory()->create(['user_id' => $other->id]),
            5,
        );
        $this->fakeGemini(['text' => 'AI の回答']);

        // Act
        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '自分の 1 通目'],
        );

        // Assert
        $response->assertOk();
    }

    public function test_ai_answers_are_not_counted(): void
    {
        // Arrange
        // 数えるのは role=user だけ。AI の応答まで数えると、実質の上限が半分になる。
        config(['ai-chat.daily_message_limit' => 2]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        AiChatMessage::factory()->create(['ai_chat_conversation_id' => $conversation->id]);
        AiChatMessage::factory()->fromAssistant()->count(3)->create([
            'ai_chat_conversation_id' => $conversation->id,
        ]);
        $this->fakeGemini(['text' => 'AI の回答']);

        // Act: 受講生の送信は 1 通なので、まだ 1 通送れる
        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '2 通目'],
        );

        // Assert
        $response->assertOk();
        $this->assertSame(2, AiChatMessage::where('role', AiChatMessageRole::User)->count());
    }

    public function test_the_modal_path_is_also_limited(): void
    {
        // Arrange
        // ⚠️ 上限の判定を Controller ではなく StoreMessageAction に置いた理由がこれ。
        //    会話作成時の「最初の質問」も同じ Action を通るので、自動的に上限が効く。
        config(['ai-chat.daily_message_limit' => 1]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $this->seedSentMessages($conversation, 1);
        $this->fakeGemini(['text' => '届かない']);

        // Act: 新しい会話を最初の質問つきで作る
        $response = $this->actingAs($student)->post(route('ai-chat.conversations.store'), [
            'source' => 'full-screen',
            'message' => 'すり抜けを狙う質問',
        ]);

        // Assert: 会話は作られるが、質問は送られない(案内つきで会話へ送られる)
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('ai_chat_messages', ['content' => 'すり抜けを狙う質問']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
