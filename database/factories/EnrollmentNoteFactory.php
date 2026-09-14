<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Database\Factories\Support\JaText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentNote>
 */
class EnrollmentNoteFactory extends Factory
{
    protected $model = EnrollmentNote::class;

    /**
     * 既定は「コーチが書いたメモ」。作成者を明示しない呼び出しでも role が coach になるようにして、
     * 受講生が author_id に入った本番ではありえない行ができないようにする。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'author_id' => User::factory()->coach(),
            'body' => JaText::coachNote(),
        ];
    }

    /**
     * 親の受講登録を指定する。手本: EnrollmentGoalFactory::forEnrollment()。
     */
    public function forEnrollment(Enrollment $enrollment): static
    {
        return $this->state(fn (): array => [
            'enrollment_id' => $enrollment->id,
        ]);
    }

    /**
     * 作成者を指定する。
     *
     * ⚠️ Laravel 標準の ->for($user) だけでは効かない。あちらは渡されたモデル名から
     *    リレーションを user と推測し、外部キーを user_id と決めるため。
     *    この Model の作成者は author() / author_id なので、標準の書き方なら
     *    ->for($user, 'author') と第2引数でリレーション名を明示することになる。
     *    テスト側の読みやすさを優先して、forEnrollment() と対になる名前のメソッドを用意した。
     */
    public function byAuthor(User $author): static
    {
        return $this->state(fn (): array => [
            'author_id' => $author->id,
        ]);
    }
}
