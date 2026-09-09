<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Database\Factories\Support\JaText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QaThread>
 */
class QaThreadFactory extends Factory
{
    protected $model = QaThread::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // スレッドを立てられるのは受講生のみ(チケット要件)。既定から受講生にしておく
            'user_id' => User::factory()->state(['role' => UserRole::Student->value]),
            'certification_id' => Certification::factory(),
            'title' => JaText::qaTitle(),
            'body' => JaText::qaBody(),
            'status' => QaThreadStatus::Open->value,
            'resolved_at' => null,
        ];
    }

    /**
     * 解決済のスレッド。status と resolved_at は必ずセットで変わるため、1つの state にまとめる。
     */
    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => QaThreadStatus::Resolved->value,
            'resolved_at' => fake()->dateTimeBetween('-2 weeks'),
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
        ]);
    }

    public function forCertification(Certification $certification): static
    {
        return $this->state(fn () => [
            'certification_id' => $certification->id,
        ]);
    }
}
