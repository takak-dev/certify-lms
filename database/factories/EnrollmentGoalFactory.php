<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Database\Factories\Support\JaText;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<EnrollmentGoal>
 */
class EnrollmentGoalFactory extends Factory
{
    protected $model = EnrollmentGoal::class;

    /**
     * 既定は「未達成 / 期日は1〜3か月先」。達成済みや期日なしは下の state で明示的に作る。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'title' => JaText::goalTitle(),
            'description' => JaText::description(),
            'target_date' => fake()->dateTimeBetween('+1 month', '+3 months')->format('Y-m-d'),
            'achieved_at' => null,
        ];
    }

    /**
     * 達成済みの目標。達成日時は「今日より前」に置く(未来に達成した記録は作らない)。
     *
     * ⚠️ 作成日時も一緒にずらす。既定では created_at が now() になるため、
     *    達成日時だけ過去にすると「立てる前に達成した」という本番ではありえない行ができる。
     *    証跡や並び順(decisions #43 の4段目は created_at 降順)を見るときに紛らわしい。
     */
    public function achieved(): static
    {
        return $this->state(function (): array {
            $achievedAt = Carbon::instance(fake()->dateTimeBetween('-2 months', '-1 day'));
            $createdAt = $achievedAt->copy()->subDays(fake()->numberBetween(7, 30));

            return [
                'achieved_at' => $achievedAt,
                'created_at' => $createdAt,
                'updated_at' => $achievedAt,
            ];
        });
    }

    /**
     * 目標期日を設定していない目標。一覧では末尾に回る(decisions #43)。
     */
    public function withoutTargetDate(): static
    {
        return $this->state(fn () => [
            'target_date' => null,
        ]);
    }

    /**
     * 期日を過ぎた未達成の目標。ダッシュボードの「N 日超過」表示の確認に使う
     * (dashboard/_partials/student/goal-timeline.blade.php:39)。
     *
     * 日数は必須。既定値を置くと「引数を無視してハードコードしてもテスト が通る」状態になり、
     * state が効いているかを検証できなくなる。
     */
    public function overdue(int $days): static
    {
        return $this->state(fn (): array => [
            'target_date' => now()->subDays($days)->toDateString(),
            'achieved_at' => null,
        ]);
    }

    /**
     * 親の受講登録を指定する。手本: QaReplyFactory::forThread()。
     */
    public function forEnrollment(Enrollment $enrollment): static
    {
        return $this->state(fn () => [
            'enrollment_id' => $enrollment->id,
        ]);
    }
}
