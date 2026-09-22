<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\IndexAsCoachAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * コーチ宛の面談一覧を取得する IndexAsCoachAction の検証(T-A-02)。
 *
 * ⚠️ この一覧は受講生向けの IndexAction と **並び順が違う**。
 *    upcoming だけ昇順で「次にやる面談」を先頭に置き、past / all は降順で直近の活動を先頭に置く。
 *    1 日に複数件を持つコーチの画面としてはこれが正しいが、コードだけ見ると
 *    IndexAction と統合できそうに見えるため、**並び順をテストで固定しておく**。
 *    統合や orderBy の共通化をすると、このテストが落ちて気づける。
 */
class IndexAsCoachActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_upcoming_is_sorted_ascending_so_the_next_meeting_comes_first(): void
    {
        // --- Arrange ---
        // わざと「遠い予定」を先に作る。作成順(created_at / id 順)で引かれている場合と
        // scheduled_at の昇順で引かれている場合とで、先頭が入れ替わるようにするため
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $later = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(10)->startOfHour(),
        ]);
        $sooner = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDay()->startOfHour(),
        ]);

        // --- Act ---
        $result = app(IndexAsCoachAction::class)($coach, 'upcoming', null, null);

        // --- Assert: 近い方が先頭 = 昇順 ---
        $this->assertSame($sooner->id, $result->first()->id);
        $this->assertSame($later->id, $result->last()->id);
    }

    public function test_past_is_sorted_descending_so_the_latest_activity_comes_first(): void
    {
        // --- Arrange ---
        // past は「終わった面談」(completed / canceled)。こちらは降順が正しい
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $older = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->subDays(10)->startOfHour(),
        ]);
        $newer = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->subDay()->startOfHour(),
        ]);

        // --- Act ---
        $result = app(IndexAsCoachAction::class)($coach, 'past', null, null);

        // --- Assert: 新しい方が先頭 = 降順(upcoming と逆) ---
        $this->assertSame($newer->id, $result->first()->id);
        $this->assertSame($older->id, $result->last()->id);
    }

    public function test_excludes_meetings_of_other_coaches(): void
    {
        // --- Arrange ---
        // 別のコーチが担当する面談を 1 件混ぜる。forCoach() による絞り込みが落ちれば、
        // この行が結果に紛れ込む。受講生側は IndexActionTest が同じ性質を固定しているので、
        // コーチ側にも同じ守りを置いて対称にする
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $own = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDay()->startOfHour(),
        ]);
        Meeting::factory()->reserved()->forCoach($otherCoach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(2)->startOfHour(),
        ]);

        // --- Act ---
        $result = app(IndexAsCoachAction::class)($coach, 'upcoming', null, null);

        // --- Assert ---
        $this->assertCount(1, $result);
        $this->assertSame($own->id, $result->first()->id);
    }

    public function test_filters_by_student(): void
    {
        // --- Arrange ---
        // 同じコーチが 2 人の受講生を担当している状態。student で絞れるかを見る
        $coach = User::factory()->coach()->create();
        $targetStudent = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();

        $target = Meeting::factory()->reserved()->forCoach($coach)->forStudent($targetStudent)->create([
            'scheduled_at' => now()->addDay()->startOfHour(),
        ]);
        Meeting::factory()->reserved()->forCoach($coach)->forStudent($otherStudent)->create([
            'scheduled_at' => now()->addDays(2)->startOfHour(),
        ]);

        // --- Act ---
        $result = app(IndexAsCoachAction::class)($coach, 'upcoming', $targetStudent->id, null);

        // --- Assert ---
        $this->assertCount(1, $result);
        $this->assertSame($target->id, $result->first()->id);
    }

    public function test_filters_by_enrollment(): void
    {
        // --- Arrange ---
        // 同じ受講生が 2 つの資格を受講しているケース。student では絞りきれず
        // enrollment での絞り込みが要る状況を作る
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $targetEnrollment = Enrollment::factory()
            ->for($student, 'user')->for(Certification::factory()->published()->create())->learning()->create();
        $otherEnrollment = Enrollment::factory()
            ->for($student, 'user')->for(Certification::factory()->published()->create())->learning()->create();

        $target = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)
            ->forEnrollment($targetEnrollment)->create(['scheduled_at' => now()->addDay()->startOfHour()]);
        Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)
            ->forEnrollment($otherEnrollment)->create(['scheduled_at' => now()->addDays(2)->startOfHour()]);

        // --- Act ---
        $result = app(IndexAsCoachAction::class)($coach, 'upcoming', null, $targetEnrollment->id);

        // --- Assert ---
        $this->assertCount(1, $result);
        $this->assertSame($target->id, $result->first()->id);
    }
}
