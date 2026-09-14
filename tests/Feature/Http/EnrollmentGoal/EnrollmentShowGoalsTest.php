<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 受講登録詳細（GET /enrollments/{enrollment}）に埋め込まれた目標一覧の検証。
 *
 * 目標は単独の一覧画面を持たず、この画面の中にだけ表示される（原典）。
 * 「誰に何が見えるか」と「誰に操作ボタンが出るか」は別の話なので、両方を分けて固定する。
 */
class EnrollmentShowGoalsTest extends TestCase
{
    use RefreshDatabase;

    private function assignCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);
    }

    /**
     * 一覧は decisions #43 の順に並ぶ。
     *
     * 未達成を先に → 目標期日が近い順 → 期日なしは末尾 → 達成済みは最後。
     * 並び順は Enrollment/ShowAction の eager load closure が与える（decisions #152）。
     * 支給 Blade は $enrollment->goals をそのまま読むだけで並べ替えないので、
     * その closure を外すとこのテストが赤くなる。
     */
    public function test_goals_are_listed_in_display_order(): void
    {
        // Arrange: 並び順を確かめるため、4 種類を「正しい順とは違う順」で作る
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        // 達成済み（最後に来るはず）
        EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create(['title' => 'D 達成済み']);
        // 期日なし（期日ありの後ろ）
        EnrollmentGoal::factory()->forEnrollment($enrollment)->withoutTargetDate()->create(['title' => 'C 期日なし']);
        // 期日が遠い
        EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => 'B 期日が遠い',
            'target_date' => now()->addMonths(6)->toDateString(),
        ]);
        // 期日が近い（先頭に来るはず）
        EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => 'A 期日が近い',
            'target_date' => now()->addDay()->toDateString(),
        ]);

        // Act
        $response = $this->actingAs($student)->get(route('enrollments.show', $enrollment));

        // Assert: 画面に現れる順番そのものを検証する
        $response->assertOk();
        $response->assertSeeInOrder(['A 期日が近い', 'B 期日が遠い', 'C 期日なし', 'D 達成済み']);
    }

    /** 本人には操作ボタン（追加フォーム・編集リンク）が出る */
    public function test_owner_sees_operation_controls(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        // Act
        $response = $this->actingAs($student)->get(route('enrollments.show', $enrollment));

        // Assert: 追加フォームの送信先と編集リンクが画面に含まれる
        $response->assertSee(route('enrollments.goals.store', $enrollment), false);
        $response->assertSee(route('enrollment-goals.edit', $goal), false);
    }

    /**
     * 担当コーチは目標を閲覧できるが、操作ボタンは出ない（原典「介入はしない」）。
     *
     * 閲覧を許しているのは EnrollmentGoalPolicy ではなく、受講登録詳細を開くときの
     * EnrollmentPolicy::view。目標側に view の ability は置いていない。
     */
    public function test_assigned_coach_can_view_goals_but_sees_no_controls(): void
    {
        // Arrange
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create(['title' => 'コーチから見える目標']);
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);

        // Act
        $response = $this->actingAs($coach)->get(route('enrollments.show', $enrollment));

        // Assert: 内容は見えるが、操作の導線は1つも無い
        $response->assertOk();
        $response->assertSee('コーチから見える目標');
        $response->assertDontSee(route('enrollments.goals.store', $enrollment), false);
        $response->assertDontSee(route('enrollment-goals.edit', $goal), false);
        $response->assertDontSee(route('enrollment-goals.markAchieved', $goal), false);
    }

    /** 管理者も同じく閲覧のみ（他機能と違い管理者に特権が無い） */
    public function test_admin_can_view_goals_but_sees_no_controls(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create(['title' => '管理者から見える目標']);
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->get(route('enrollments.show', $enrollment));

        // Assert
        $response->assertOk();
        $response->assertSee('管理者から見える目標');
        $response->assertDontSee(route('enrollment-goals.edit', $goal), false);
    }

    /**
     * 0 件のときのメッセージが閲覧者で変わる（enrollment-goal/_form.blade.php:48）。
     *
     * 本人には「まだ目標が登録されていません。」、他者には
     * 「この受講生はまだ目標を登録していません。」と出し分ける。
     */
    public function test_empty_message_differs_by_viewer(): void
    {
        // Arrange: 目標を1件も持たない受講登録
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $admin = User::factory()->admin()->create();

        // Act & Assert: 本人向けの文面
        $this->actingAs($student)
            ->get(route('enrollments.show', $enrollment))
            ->assertSee('まだ目標が登録されていません。');

        // Act & Assert: 他者向けの文面
        $this->actingAs($admin)
            ->get(route('enrollments.show', $enrollment))
            ->assertSee('この受講生はまだ目標を登録していません。');
    }

    /**
     * 受講解除すると、配下の目標は消える（decisions #135 / 面談2 Q44）。
     *
     * 受講登録詳細は ->withTrashed() 付きなので解除後も開ける（enrollments.show のルート定義）が、
     * そこに目標は 1 件も残っていない。追加フォームも出ない
     * （EnrollmentGoalPolicy::create が親の trashed() を見て false を返す。decisions #134）。
     *
     * ⚠️ ここは画面越しの確認。受講解除そのものは EnrollmentController::destroy を通す
     *    （モデルを直接 delete すると目標を消す処理が走らず、現実と違う状態になる）。
     */
    public function test_goals_are_gone_after_unenrolling(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        EnrollmentGoal::factory()->forEnrollment($enrollment)->create(['title' => '解除で消える目標']);

        // Act: 画面から受講解除する
        $this->actingAs($student)->delete(route('enrollments.destroy', $enrollment));
        $response = $this->actingAs($student)->get(route('enrollments.show', $enrollment));

        // Assert: 画面は開けるが目標は残っていない
        $response->assertOk();
        $response->assertDontSee('解除で消える目標');
        // 追加フォームも出ない（書けない場所に入力欄を出さない）
        $response->assertDontSee(route('enrollments.goals.store', $enrollment), false);
    }

    /**
     * 「親だけ論理削除され、目標が残った状態」でも画面が落ちない。
     *
     * ⚠️ 受講解除では目標ごと消える（decisions #135）ので、実運用ではこの状態にならない。
     *    ここで確かめるのは Enrollment/ShowAction と EnrollmentGoalPolicy の二重の守り——
     *    eager load した $goal->enrollment が null になる経路を通しても、
     *
     *    @can が TypeError を投げず（500 にならず）、操作ボタンだけが消えること。
     *
     *    ShowAction のコメントが「ここで 500 になりうる」と書いている当の経路なので、
     *    先読みの仕方を将来変えたときに壊れ方を検知できるようにしておく。
     */
    public function test_screen_survives_when_only_the_parent_is_soft_deleted(): void
    {
        // Arrange: Action を通さずモデルを直接論理削除する（目標は残る）
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create(['title' => '親だけ消えた目標']);
        $enrollment->delete();

        // 前提の確認: 目標の行は残っている
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id]);

        // Act
        $response = $this->actingAs($student)->get(route('enrollments.show', $enrollment));

        // Assert: 500 にならず、内容は読めるが操作の導線は出ない
        $response->assertOk();
        $response->assertSee('親だけ消えた目標');
        $response->assertDontSee(route('enrollments.goals.store', $enrollment), false);
        $response->assertDontSee(route('enrollment-goals.edit', $goal), false);
        $response->assertDontSee(route('enrollment-goals.destroy', $goal), false);
        $response->assertDontSee(route('enrollment-goals.markAchieved', $goal), false);
    }

    /**
     * 他の受講生は受講登録詳細そのものに入れないので、目標にも到達できない。
     *
     * 原典「他受講生は他人の受講登録詳細画面自体にアクセスできないため、
     * 配下の目標一覧にも到達できない」がこれ。
     */
    public function test_other_student_cannot_reach_the_screen_at_all(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        EnrollmentGoal::factory()->forEnrollment($enrollment)->create(['title' => '他人には見えない目標']);
        $otherStudent = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($otherStudent)->get(route('enrollments.show', $enrollment));

        // Assert: 画面ごと 403
        $response->assertForbidden();
    }
}
