<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 個人学習目標の追加（POST /enrollments/{enrollment}/goals）の検証。
 *
 * このチケットの認可は「受講生本人だけが操作でき、管理者にも特権が無い」という、
 * 他機能と逆の形をしている。誰が追加できて誰ができないかをここで固定する。
 *
 * 手本: tests/Feature/Http/QaReply/StoreTest.php（親リソース配下への POST）
 */
class StoreTest extends TestCase
{
    use RefreshDatabase;

    /** 受講中の受講生と、その人の受講登録を1組作る */
    private function learningEnrollment(?User $student = null, ?Certification $certification = null): Enrollment
    {
        $student ??= User::factory()->student()->create();
        $certification ??= Certification::factory()->published()->create();

        return Enrollment::factory()->for($student)->for($certification)->learning()->create();
    }

    /** 資格の担当コーチに割り当てる（中間テーブルは ULID 主キーと割当メタ情報を持つ） */
    private function assignCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_owner_student_can_add_goal(): void
    {
        // Arrange
        $enrollment = $this->learningEnrollment();

        // Act
        $response = $this->actingAs($enrollment->user)->post(route('enrollments.goals.store', $enrollment), [
            'title' => '過去問を 5 年分解き終える',
            'target_date' => '2026-12-31',
            'description' => '直近 5 年分を時間を計って解く。',
        ]);

        // Assert: 目標は独立した画面を持たないので、常に親の受講登録詳細へ戻る
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'title' => '過去問を 5 年分解き終える',
            'achieved_at' => null,   // 追加した直後は必ず未達成
        ]);
    }

    /** 期日と詳細は任意。タイトルだけでも追加できる */
    public function test_optional_fields_can_be_omitted(): void
    {
        // Arrange
        $enrollment = $this->learningEnrollment();

        // Act
        $response = $this->actingAs($enrollment->user)->post(route('enrollments.goals.store', $enrollment), [
            'title' => '学習の習慣を作る',
        ]);

        // Assert
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('enrollment_goals', [
            'title' => '学習の習慣を作る',
            'target_date' => null,
            'description' => null,
        ]);
    }

    /**
     * 目標期日は過去日でも登録できる（docs/tickets/S-B-05.md §3.3）。
     *
     * 既存の目標受験日（Enrollment/StoreRequest.php:35）は after:today だが、
     * 目標は「期日を過ぎても残る記録」なので同じ制約を付けていない。
     * これを付けてしまうと、期日を過ぎた目標のタイトルすら直せなくなる。
     */
    public function test_target_date_in_the_past_is_accepted(): void
    {
        // Arrange
        $enrollment = $this->learningEnrollment();

        // Act
        $response = $this->actingAs($enrollment->user)->post(route('enrollments.goals.store', $enrollment), [
            'title' => '先月までに終える予定だった範囲',
            'target_date' => now()->subMonth()->toDateString(),
        ]);

        // Assert
        $response->assertSessionHas('success');
        $response->assertSessionHasNoErrors();
    }

    public function test_title_is_required(): void
    {
        // Arrange
        $enrollment = $this->learningEnrollment();

        // Act
        $response = $this->actingAs($enrollment->user)->post(route('enrollments.goals.store', $enrollment), [
            'title' => '',
        ]);

        // Assert
        $response->assertSessionHasErrors('title');
        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    /**
     * タイトルの上限は 100 文字（画面の maxlength="100" に合わせる）。
     *
     * 「101 が弾かれる」だけでは max:99 の書き間違いを検知できないので、
     * 上限ちょうどが通ることも固定する（B-B-05 で学んだ境界値の見方）。
     */
    public function test_title_boundary_is_one_hundred_characters(): void
    {
        // Arrange
        $enrollment = $this->learningEnrollment();

        // Act & Assert: 100 文字ちょうどは通る
        $this->actingAs($enrollment->user)
            ->post(route('enrollments.goals.store', $enrollment), ['title' => str_repeat('あ', 100)])
            ->assertSessionHasNoErrors();

        // Act & Assert: 101 文字は弾かれる
        $this->actingAs($enrollment->user)
            ->post(route('enrollments.goals.store', $enrollment), ['title' => str_repeat('あ', 101)])
            ->assertSessionHasErrors('title');

        // 通ったのは 1 件だけ
        $this->assertDatabaseCount('enrollment_goals', 1);
    }

    /** 詳細の上限は 1000 文字（画面の :maxlength="1000" に合わせる） */
    public function test_description_boundary_is_one_thousand_characters(): void
    {
        // Arrange
        $enrollment = $this->learningEnrollment();

        // Act & Assert: 1000 文字ちょうどは通る
        $this->actingAs($enrollment->user)
            ->post(route('enrollments.goals.store', $enrollment), [
                'title' => '詳細の境界値',
                'description' => str_repeat('い', 1000),
            ])
            ->assertSessionHasNoErrors();

        // Act & Assert: 1001 文字は弾かれる
        $this->actingAs($enrollment->user)
            ->post(route('enrollments.goals.store', $enrollment), [
                'title' => '詳細の境界値',
                'description' => str_repeat('い', 1001),
            ])
            ->assertSessionHasErrors('description');
    }

    /**
     * 達成日時はフォームから書き込めない。
     *
     * 防御は 2 層ある。FormRequest の rules() に achieved_at が無いので validated() から落ち、
     * Model の $fillable にも無いので仮に渡っても書き込まれない。
     * これを許すと、達成マークの権限判定（markAchieved）を通さずに達成済みにできてしまう。
     */
    public function test_achieved_at_cannot_be_mass_assigned(): void
    {
        // Arrange
        $enrollment = $this->learningEnrollment();

        // Act: 画面に無い欄を偽造して送る
        $this->actingAs($enrollment->user)->post(route('enrollments.goals.store', $enrollment), [
            'title' => '偽装テスト',
            'achieved_at' => '2020-01-01 00:00:00',
        ]);

        // Assert: 作成はされるが、達成日時は入っていない
        $this->assertDatabaseHas('enrollment_goals', [
            'title' => '偽装テスト',
            'achieved_at' => null,
        ]);
    }

    /** 他人の受講登録には追加できない（URL の ID を書き換えても通らない） */
    public function test_other_student_cannot_add_goal(): void
    {
        // Arrange
        $enrollment = $this->learningEnrollment();
        $otherStudent = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($otherStudent)
            ->post(route('enrollments.goals.store', $enrollment), ['title' => '他人の受講登録へ']);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    /**
     * 担当コーチも管理者も追加できない（原典「介入はしない」）。
     *
     * ⚠️ このプロジェクトの他機能では管理者が最強の権限を持つが、個人目標は例外。
     *    姉妹チケット S-B-07（受講生メモ）は権限が真逆なので、混同するとここが落ちる。
     */
    public function test_coach_and_admin_cannot_add_goal(): void
    {
        // Arrange: コーチはその資格の担当にしておく（担当ですら追加できないことを固定する）
        $certification = Certification::factory()->published()->create();
        $enrollment = $this->learningEnrollment(null, $certification);
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);
        $admin = User::factory()->admin()->create();

        // Act & Assert
        foreach ([$coach, $admin] as $staff) {
            $this->actingAs($staff)
                ->post(route('enrollments.goals.store', $enrollment), ['title' => 'スタッフによる追加'])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    /**
     * 修了した受講生は追加できない（ルートの active-learning ミドルウェアが弾く）。
     *
     * 原典「受講停止状態（修了済 / 退会済 / 招待中）の受講生は目標操作にも到達しない」を
     * 満たしているのはこのミドルウェア。Policy はユーザーの状態を見ていない。
     */
    public function test_graduated_student_cannot_add_goal(): void
    {
        // Arrange
        $enrollment = $this->learningEnrollment();
        $enrollment->user->forceFill(['status' => UserStatus::Graduated])->save();

        // Act
        $response = $this->actingAs($enrollment->user->fresh())
            ->post(route('enrollments.goals.store', $enrollment), ['title' => '修了後の追加']);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    /**
     * 受講解除した受講登録には追加できない（decisions #132 / #135）。
     *
     * 受講解除は Enrollment の論理削除。目標のルートに ->withTrashed() を付けていないので、
     * ルートモデルバインディングが解決できず 404 になる。
     * 書き込みの拒否はルート層（404）、画面の出し分けは Policy の trashed() 判定（decisions #134）
     * という二段構え。update / destroy は {goal} が解決できるので 403 になる（UpdateTest 参照）。
     */
    public function test_cannot_add_goal_to_soft_deleted_enrollment(): void
    {
        // Arrange
        $enrollment = $this->learningEnrollment();
        $student = $enrollment->user;
        $enrollment->delete();   // 受講解除に相当

        // Act
        $response = $this->actingAs($student)
            ->post(route('enrollments.goals.store', $enrollment), ['title' => '解除後の追加']);

        // Assert: 403 ではなく 404（存在しないものとして扱う）
        $response->assertNotFound();
        $this->assertDatabaseCount('enrollment_goals', 0);
    }
}
