<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use Database\Factories\Support\JaText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiChatMessage>
 */
class AiChatMessageFactory extends Factory
{
    protected $model = AiChatMessage::class;

    /**
     * 既定は「受講生が送った、応答済みの質問」。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_chat_conversation_id' => AiChatConversation::factory(),
            'role' => AiChatMessageRole::User,
            'content' => JaText::qaBody(),
            'status' => AiChatMessageStatus::Completed,
            'error_detail' => null,
        ];
    }

    /**
     * AI の応答(完了)。
     */
    public function fromAssistant(): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::Assistant,
            'content' => JaText::paragraph(),
            'status' => AiChatMessageStatus::Completed,
        ]);
    }

    /**
     * AI の応答待ち。本文は空 —— 支給 Blade
     * resources/views/ai-chat/_partials/message-bubble.blade.php:45 が
     * 「Pending かつ本文が空」のときだけローディングのドットを出す。
     */
    public function pending(): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::Assistant,
            'content' => '',
            'status' => AiChatMessageStatus::Pending,
        ]);
    }

    /**
     * AI の応答が失敗した状態。原典の初期データが要求する
     * 「AI 応答がエラー状態のもの」をこの state で作る。
     *
     * ⚠️ error_detail に HTTP ステータス番号を入れるのは飾りではない ——
     *    支給 Blade message-bubble.blade.php:55-56 が str_contains で番号を探し、
     *    受講生向けの文言を出し分けている。
     */
    public function failed(string $errorDetail = 'Gemini API error: HTTP 503 Service Unavailable'): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::Assistant,
            'content' => '',
            'status' => AiChatMessageStatus::Error,
            'error_detail' => $errorDetail,
        ]);
    }
}
