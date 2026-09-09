<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Database\Factories\Support\JaText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QaReply>
 */
class QaReplyFactory extends Factory
{
    protected $model = QaReply::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'qa_thread_id' => QaThread::factory(),
            'user_id' => User::factory(),
            'body' => JaText::qaReply(),
        ];
    }

    /**
     * 受講生による回答。管理者は回答できないため、ロールを固定できる state を用意する。
     */
    public function fromStudent(): static
    {
        return $this->state(fn () => [
            'user_id' => User::factory()->state(['role' => UserRole::Student->value]),
        ]);
    }

    /**
     * コーチによる回答。
     */
    public function fromCoach(): static
    {
        return $this->state(fn () => [
            'user_id' => User::factory()->state(['role' => UserRole::Coach->value]),
        ]);
    }

    public function forThread(QaThread $thread): static
    {
        return $this->state(fn () => [
            'qa_thread_id' => $thread->id,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
        ]);
    }
}
