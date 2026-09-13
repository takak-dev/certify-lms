<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Dashboard;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講生ダッシュボードの個人目標タイムラインの検証。
 *
 * ⚠️ ここは支給コードが先に存在していた箇所で、こちらが名前を合わせる側。
 *    - FetchStudentDashboardAction.php:296-299 が EnrollmentGoal::query()->displayOrder() を呼ぶ
 *    - dashboard/_partials/student/goal-timeline.blade.php:22 が $goal->isAchieved() を呼ぶ
 *    どちらかの名前を変えるとこの画面が落ちる。名前の固定がこのテストの主目的。
 *
 * 支給コードは Route::has('enrollments.goals.store') が false のとき空を返す作りになっており
 * （機能未提供時の防御）、ルートを登録した今はここを通る。
 */
class StudentGoalTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_goals_appear_on_dashboard(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        EnrollmentGoal::factory()->forEnrollment($enrollment)->create(['title' => 'ダッシュボードに出る目標']);

        // Act
        $response = $this->actingAs($student)->get(route('dashboard.index'));

        // Assert
        $response->assertOk();
        $response->assertSee('ダッシュボードに出る目標');
    }

    /**
     * タイムラインは decisions #43 の順に並ぶ。
     *
     * 受講登録詳細と同じ順序になることが要点。両画面とも同じ scopeDisplayOrder() を使うが、
     * 呼ぶ場所が違う——受講登録詳細は Enrollment/ShowAction の eager load closure、
     * ダッシュボードは FetchStudentDashboardAction が直接呼ぶ（支給コード）。
     * スコープの中身を変えたとき、両方に効くことをこのテストで確かめる。
     */
    public function test_goals_are_ordered_by_display_order(): void
    {
        // Arrange: 正しい順とは違う順で作る
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create(['title' => 'D 達成済み']);
        EnrollmentGoal::factory()->forEnrollment($enrollment)->withoutTargetDate()->create(['title' => 'C 期日なし']);
        EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => 'B 期日が遠い',
            'target_date' => now()->addMonths(6)->toDateString(),
        ]);
        EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => 'A 期日が近い',
            'target_date' => now()->addDay()->toDateString(),
        ]);

        // Act
        $response = $this->actingAs($student)->get(route('dashboard.index'));

        // Assert
        $response->assertSeeInOrder(['A 期日が近い', 'B 期日が遠い', 'C 期日なし', 'D 達成済み']);
    }

    /**
     * 複数の受講登録の目標が1本のタイムラインに混ざる。
     *
     * 支給コードは受講登録ごとではなく「その受講生の全目標」を1つの流れとして出す
     * （FetchStudentDashboardAction.php:297 が user_id で絞っている）。
     */
    public function test_goals_from_multiple_enrollments_are_merged(): void
    {
        // Arrange: 2つの資格にそれぞれ目標を置く
        $student = User::factory()->student()->create();
        $first = Enrollment::factory()->for($student)->learning()->create();
        $second = Enrollment::factory()->for($student)->learning()->create();
        EnrollmentGoal::factory()->forEnrollment($first)->create(['title' => '1つ目の資格の目標']);
        EnrollmentGoal::factory()->forEnrollment($second)->create(['title' => '2つ目の資格の目標']);

        // Act
        $response = $this->actingAs($student)->get(route('dashboard.index'));

        // Assert: 両方出る
        $response->assertSee('1つ目の資格の目標');
        $response->assertSee('2つ目の資格の目標');
    }

    /** 他人の目標は出ない */
    public function test_other_students_goals_are_not_shown(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student)->learning()->create();

        $otherStudent = User::factory()->student()->create();
        $otherEnrollment = Enrollment::factory()->for($otherStudent)->learning()->create();
        EnrollmentGoal::factory()->forEnrollment($otherEnrollment)->create(['title' => '他人の目標']);

        // Act
        $response = $this->actingAs($student)->get(route('dashboard.index'));

        // Assert
        $response->assertOk();
        $response->assertDontSee('他人の目標');
    }

    /**
     * 期日を過ぎた未達成の目標には「N 日超過」が出る。
     *
     * 支給 Blade（dashboard/_partials/student/goal-timeline.blade.php:25-40）が
     * 本日からの残日数を計算して文面と色を切り替えている。この分岐は目標のデータが無いと動かないので、
     * S-B-05 で初めて検証できるようになった。
     * ⚠️ 残日数の計算は $goal->target_date が Carbon であることに依存する（Model の $casts）。
     *    キャストを外すと文字列に ->diffInDays() を呼んで落ちる。
     */
    public function test_overdue_goal_shows_days_past_due(): void
    {
        // Arrange: 期日を5日過ぎた未達成の目標
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        // 期日は overdue() の引数が決める。ここで target_date を上書きすると
        // state が効いているかどうかを検証できなくなる。
        // 5 を渡すのは、引数を無視してハードコードした実装を弾くため（3 だと既定値と区別が付かない）
        EnrollmentGoal::factory()->forEnrollment($enrollment)->overdue(5)->create([
            'title' => '期日を過ぎた目標',
        ]);

        // Act
        $response = $this->actingAs($student)->get(route('dashboard.index'));

        // Assert
        $response->assertOk();
        $response->assertSee('期日を過ぎた目標');
        $response->assertSee('5 日超過');
    }

    /** 目標が1件も無いときは空状態の文面が出る（支給 Blade の goal-timeline.blade.php:14-16） */
    public function test_empty_state_is_shown_when_no_goals(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student)->learning()->create();

        // Act
        $response = $this->actingAs($student)->get(route('dashboard.index'));

        // Assert
        $response->assertOk();
        $response->assertSee('個人目標はまだありません。');
    }
}
