<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 個人学習目標の編集画面（GET /enrollment-goals/{goal}/edit）と
 * 更新（PATCH /enrollment-goals/{goal}）の検証。
 *
 * 編集ページは目標が持つ唯一の専用画面。一覧・詳細は受講登録詳細に埋め込まれているため、
 * 「目標を読むための URL」はこの edit だけになる。他人の目標を覗けないことをここで固定する。
 */
class UpdateTest extends TestCase
{
    use RefreshDatabase;

    /** 受講中の受講生・受講登録・目標を1組作る */
    private function ownedGoal(?User $student = null, ?Certification $certification = null): EnrollmentGoal
    {
        $student ??= User::factory()->student()->create();
        $certification ??= Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();

        return EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
    }

    private function assignCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_owner_student_can_open_edit_screen(): void
    {
        // Arrange
        $goal = $this->ownedGoal();

        // Act
        $response = $this->actingAs($goal->enrollment->user)->get(route('enrollment-goals.edit', $goal));

        // Assert: 画面が開き、編集対象の値が入っている
        $response->assertOk();
        $response->assertSee($goal->title);
    }

    /**
     * 他人の目標の編集画面は開けない。
     *
     * 目標には一覧・詳細の URL が無く、外から狙えるのはこの edit だけ。
     * ここが通ると他人の目標の内容が読めてしまう（B-B-09 と同じ IDOR の形）。
     */
    public function test_other_student_cannot_open_edit_screen(): void
    {
        // Arrange
        $goal = $this->ownedGoal();
        $otherStudent = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($otherStudent)->get(route('enrollment-goals.edit', $goal));

        // Assert
        $response->assertForbidden();
    }

    /** 担当コーチと管理者は閲覧のみ。編集画面には入れない */
    public function test_coach_and_admin_cannot_open_edit_screen(): void
    {
        // Arrange
        $certification = Certification::factory()->published()->create();
        $goal = $this->ownedGoal(null, $certification);
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);
        $admin = User::factory()->admin()->create();

        // Act & Assert
        foreach ([$coach, $admin] as $staff) {
            $this->actingAs($staff)
                ->get(route('enrollment-goals.edit', $goal))
                ->assertForbidden();
        }
    }

    public function test_owner_student_can_update_goal(): void
    {
        // Arrange
        $goal = $this->ownedGoal();

        // Act
        $response = $this->actingAs($goal->enrollment->user)->patch(route('enrollment-goals.update', $goal), [
            'title' => '書き換えたタイトル',
            'target_date' => '2027-03-31',
            'description' => '書き換えた詳細。',
        ]);

        // Assert: 目標に詳細画面が無いので、親の受講登録詳細へ戻る
        $response->assertRedirect(route('enrollments.show', $goal->enrollment_id));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => '書き換えたタイトル',
            'description' => '書き換えた詳細。',
        ]);
    }

    /**
     * 達成済みの目標も編集できる。そのとき達成日時は失われない。
     *
     * 支給 Blade は編集ボタンを @if ($goal->achieved_at) の外に置いている
     * （enrollment-goal/_form.blade.php:93）ので、達成済みでも編集動線がある。
     * 更新で achieved_at が消えると、達成した記録が編集のたびに飛ぶことになる。
     */
    public function test_updating_achieved_goal_keeps_achieved_at(): void
    {
        // Arrange: 達成済みの目標
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create();
        $achievedAt = $goal->achieved_at;

        // Act
        $this->actingAs($student)->patch(route('enrollment-goals.update', $goal), [
            'title' => '達成後に書き換えた',
        ]);

        // Assert: タイトルは変わり、達成日時はそのまま
        $goal->refresh();
        $this->assertSame('達成後に書き換えた', $goal->title);
        $this->assertNotNull($goal->achieved_at);
        $this->assertTrue($achievedAt->equalTo($goal->achieved_at));
    }

    /** 更新でも達成日時は書き込めない（追加時と同じ 2 層の防御） */
    public function test_achieved_at_cannot_be_mass_assigned_on_update(): void
    {
        // Arrange: 未達成の目標
        $goal = $this->ownedGoal();

        // Act: 画面に無い欄を偽造して送る
        $this->actingAs($goal->enrollment->user)->patch(route('enrollment-goals.update', $goal), [
            'title' => '偽装テスト',
            'achieved_at' => '2020-01-01 00:00:00',
        ]);

        // Assert: 未達成のまま
        $goal->refresh();
        $this->assertNull($goal->achieved_at);
    }

    public function test_title_is_required_on_update(): void
    {
        // Arrange
        $goal = $this->ownedGoal();
        $originalTitle = $goal->title;

        // Act
        $response = $this->actingAs($goal->enrollment->user)
            ->patch(route('enrollment-goals.update', $goal), ['title' => '']);

        // Assert: 検証に落ち、元の値のまま
        $response->assertSessionHasErrors('title');
        $this->assertSame($originalTitle, $goal->refresh()->title);
    }

    /** 上限ちょうど（100 文字）が通り、+1 が弾かれる */
    public function test_title_boundary_on_update(): void
    {
        // Arrange
        $goal = $this->ownedGoal();
        $student = $goal->enrollment->user;

        // Act & Assert
        $this->actingAs($student)
            ->patch(route('enrollment-goals.update', $goal), ['title' => str_repeat('あ', 100)])
            ->assertSessionHasNoErrors();

        $this->actingAs($student)
            ->patch(route('enrollment-goals.update', $goal), ['title' => str_repeat('あ', 101)])
            ->assertSessionHasErrors('title');
    }

    /**
     * 親の受講登録が論理削除されている目標は更新できない。
     *
     * ⚠️ 受講解除では配下の目標を物理削除する（decisions #135 / 面談2 Q44）ので、
     *    実運用でこの状態（親だけ解除され目標が残っている）にはならない。
     *    ここで固めているのは二重の守り——モデルを直接 delete した場合や、
     *    将来 Action を経由しない解除経路が増えた場合に、書き込みが漏れないこと。
     *
     *
     * ⚠️ 追加（store）は 404 だが、こちらは 403 になる。理由が違う。
     *    - store: URL の {enrollment} が論理削除済みで解決できない → 404
     *    - update: {goal} 自体は生きているので解決できる。その後 Policy が
     *      $goal->enrollment を null として受け取り false を返す → 403
     */
    public function test_cannot_update_goal_of_soft_deleted_enrollment(): void
    {
        // Arrange
        $goal = $this->ownedGoal();
        $student = $goal->enrollment->user;
        $goal->enrollment->delete();   // 受講解除に相当

        // Act
        $response = $this->actingAs($student)
            ->patch(route('enrollment-goals.update', $goal), ['title' => '解除後の更新']);

        // Assert
        $response->assertForbidden();
        $this->assertNotSame('解除後の更新', $goal->refresh()->title);
    }

    /** 修了した受講生は更新できない（active-learning ミドルウェア） */
    public function test_graduated_student_cannot_update_goal(): void
    {
        // Arrange
        $goal = $this->ownedGoal();
        $student = $goal->enrollment->user;
        $student->forceFill(['status' => UserStatus::Graduated])->save();

        // Act
        $response = $this->actingAs($student->fresh())
            ->patch(route('enrollment-goals.update', $goal), ['title' => '修了後の更新']);

        // Assert
        $response->assertForbidden();
    }
}
