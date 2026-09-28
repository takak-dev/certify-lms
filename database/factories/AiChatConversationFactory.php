<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Database\Factories\Support\JaText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiChatConversation>
 */
class AiChatConversationFactory extends Factory
{
    protected $model = AiChatConversation::class;

    /**
     * 既定は「全般相談」(教材にも資格にも紐づかない会話)。
     *
     * 利用できるのは学習中の受講生だけなので、オーナーも student() + inProgress() で作る
     * (UserFactory:48,63)。タイトルは質問掲示板と同じ日本語生成器を借りる —— どちらも
     * 「受講生が書いた質問の見出し」で性質が同じため、JaText に新しいメソッドを足さない。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student()->inProgress(),
            'enrollment_id' => null,
            'section_id' => null,
            'title' => JaText::qaTitle(),
            'last_message_at' => null,
        ];
    }

    /**
     * 教材から始めた会話(コンテキストバッジが 📚 になる)。
     */
    public function forSection(?Section $section = null): static
    {
        return $this->state(fn () => [
            'section_id' => $section?->id ?? Section::factory(),
        ]);
    }

    /**
     * 受講中の資格に紐づく会話(コンテキストバッジが 🎓 になる)。
     */
    public function forEnrollment(?Enrollment $enrollment = null): static
    {
        return $this->state(fn () => [
            'enrollment_id' => $enrollment?->id ?? Enrollment::factory(),
        ]);
    }

    /**
     * 最終発言時刻を指定する(履歴サイドバーの「今日 / 過去 7 日 / 過去 30 日」の検証用)。
     * 手本: ChatRoomFactory::withMessageAt()。
     */
    public function withMessageAt(\DateTimeInterface $at): static
    {
        return $this->state(fn () => [
            'last_message_at' => $at,
        ]);
    }
}
