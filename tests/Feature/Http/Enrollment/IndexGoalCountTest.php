<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Enrollment;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講中資格の一覧（GET /enrollments）に出る個人目標の件数の検証。
 *
 * ⚠️ この画面は支給済みで、enrollment/_partials/student-index.blade.php:109 が
 *    {{ $enrollment->goals_count ?? 0 }} と書いている。この属性は
 *    IndexAction の withCount('goals') が作る。
 *
 *    付け忘れても ?? 0 に握り潰されて例外が出ず、一覧が常に「0 件」と表示されるだけになる。
 *    つまり画面を見ただけでは壊れていることに気付けない。そのための回帰テスト。
 */
class IndexGoalCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_count_is_shown_for_each_enrollment(): void
    {
        // Arrange: 目標を3件持つ受講登録を1つ用意する
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        EnrollmentGoal::factory()->forEnrollment($enrollment)->count(3)->create();

        // Act
        $response = $this->actingAs($student)->get(route('enrollments.index'));

        // Assert: 属性そのものを検証する。画面の文字列だけ見ると
        // 他の数値（残り日数など）と区別が付かず、誤検知しやすい
        $response->assertOk();
        $shown = $response->viewData('enrollments')->firstWhere('id', $enrollment->id);
        $this->assertSame(3, $shown->goals_count);
    }

    /** 目標が1件も無いときは 0。NULL ではない（COUNT(*) は行が無ければ 0 を返す） */
    public function test_goal_count_is_zero_when_no_goals(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        // Act
        $response = $this->actingAs($student)->get(route('enrollments.index'));

        // Assert
        $shown = $response->viewData('enrollments')->firstWhere('id', $enrollment->id);
        $this->assertSame(0, $shown->goals_count);
    }

    /** 他人の目標は数に入らない（件数が受講登録ごとに正しく分かれる） */
    public function test_goal_count_does_not_leak_across_enrollments(): void
    {
        // Arrange: 同じ受講生が2つの資格に登録し、片方にだけ目標を置く
        $student = User::factory()->student()->create();
        $withGoals = Enrollment::factory()->for($student)->learning()->create();
        $withoutGoals = Enrollment::factory()->for($student)->learning()->create();
        EnrollmentGoal::factory()->forEnrollment($withGoals)->count(2)->create();

        // 別の受講生の目標（こちらの数に混ざってはいけない）
        $otherStudent = User::factory()->student()->create();
        $otherEnrollment = Enrollment::factory()->for($otherStudent)->learning()->create();
        EnrollmentGoal::factory()->forEnrollment($otherEnrollment)->count(5)->create();

        // Act
        $response = $this->actingAs($student)->get(route('enrollments.index'));

        // Assert
        $rows = $response->viewData('enrollments');
        $this->assertSame(2, $rows->firstWhere('id', $withGoals->id)->goals_count);
        $this->assertSame(0, $rows->firstWhere('id', $withoutGoals->id)->goals_count);
    }
}
