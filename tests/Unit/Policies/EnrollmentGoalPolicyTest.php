<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use App\Policies\EnrollmentGoalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 個人学習目標の認可ポリシーを単体で検証する。
 *
 * このチケットの認可は他の機能と形が違う——「受講生本人だけが操作でき、管理者にも特権が無い」。
 * 姉妹チケット S-B-07(受講生メモ)は権限が真逆なので、そちらを書くときに
 * このファイルをコピーして書き換え忘れると、ここのテストが落ちて気付ける。
 *
 * 手本: tests/Unit/Policies/PlanPolicyTest.php(ロール別に1メソッド) /
 *       tests/Unit/Policies/EnrollmentPolicyTest.php(担当コーチの作り方)
 */
class EnrollmentGoalPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 目標の持ち主(受講生本人)は、追加・編集・削除・達成マーク・解除の5つすべてを行える。
     */
    public function test_owner_student_can_perform_all_goal_operations(): void
    {
        // Arrange: 受講生と、その人の受講登録配下の目標を1件用意する
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $policy = new EnrollmentGoalPolicy;

        // Assert: create だけは親の受講登録を、残りは目標そのものを judge の対象にする
        // (目標を追加する時点では、まだ目標の行が存在しないため)
        $this->assertTrue($policy->create($student, $enrollment));
        $this->assertTrue($policy->update($student, $goal));
        $this->assertTrue($policy->delete($student, $goal));
        $this->assertTrue($policy->markAchieved($student, $goal));
        $this->assertTrue($policy->unmarkAchieved($student, $goal));
    }

    /**
     * 担当コーチと管理者は「閲覧のみ」。5つの操作はいずれも許可されない。
     *
     * ⚠️ このプロジェクトの他の機能では管理者が最も強い権限を持つが、
     *    個人目標は原典が「コーチ / 管理者 / 他受講生は実行不可」「介入はしない」と明記している。
     *    そのため管理者にも特権を与えていない。ここが S-B-07 と正反対になる分かれ目。
     */
    public function test_assigned_coach_and_admin_cannot_operate_goals(): void
    {
        // Arrange: 受講生・その受講登録・目標に加えて、
        //          「その資格の担当コーチ」と管理者を用意する。
        //          担当コーチにしておくのが要点——担当ですら操作できないことを固定したい。
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        // コーチを資格の担当に割り当てる(手本: EnrollmentPolicyTest::test_coach_can_view_only_assigned_certification)
        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $policy = new EnrollmentGoalPolicy;

        // Assert: 2人とも5つすべて false
        foreach ([$coach, $admin] as $staff) {
            $this->assertFalse($policy->create($staff, $enrollment));
            $this->assertFalse($policy->update($staff, $goal));
            $this->assertFalse($policy->delete($staff, $goal));
            $this->assertFalse($policy->markAchieved($staff, $goal));
            $this->assertFalse($policy->unmarkAchieved($staff, $goal));
        }
    }

    /**
     * 他の受講生は、他人の目標を操作できない(IDOR 対策)。
     *
     * 画面には出ないが、URL の ID を他人のものに書き換えて直接リクエストされる経路がある。
     * 「ロールが student である」だけでは通してはいけない、という判定を固定する。
     */
    public function test_other_student_cannot_operate_someone_elses_goals(): void
    {
        // Arrange: 所有者と、無関係な別の受講生
        $owner = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $policy = new EnrollmentGoalPolicy;

        // Assert: ロールは同じ student でも、受講登録の持ち主が違うので全部 false
        $this->assertFalse($policy->create($otherStudent, $enrollment));
        $this->assertFalse($policy->update($otherStudent, $goal));
        $this->assertFalse($policy->delete($otherStudent, $goal));
        $this->assertFalse($policy->markAchieved($otherStudent, $goal));
        $this->assertFalse($policy->unmarkAchieved($otherStudent, $goal));
    }

    /**
     * 親の受講登録が論理削除されている目標は、持ち主本人でも操作できない。
     *
     * ⚠️ 受講解除では配下の目標を物理削除する（decisions #135 / 面談2 Q44）ので、
     *    実運用でこの状態（親だけ解除され目標が残っている）にはならない。
     *    ここで固めているのは二重の守り——モデルを直接 delete した場合や、
     *    将来 Action を経由しない解除経路が増えた場合に、書き込みが漏れないこと。
     *
     * 受講解除は Enrollment の論理削除(SoftDeletes)で行う。
     * belongsTo は既定で論理削除済みの親を返さないため $goal->enrollment が null になり、
     * Policy はそれを false に倒す。
     *
     * 解除済みの受講登録詳細は withTrashed 付きで開ける(enrollments.show のルート定義)。
     * そこで @can が評価されるため、null を想定しないと TypeError(500)で画面が落ちる。
     */
    public function test_owner_cannot_operate_goals_of_soft_deleted_enrollment(): void
    {
        // Arrange: 目標を作ってから、親の受講登録を論理削除する(＝受講解除と同じ状態)
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        // Act: 受講解除に相当する操作
        $enrollment->delete();

        // 親のリレーションは一度読むとキャッシュされるので、取り直してから判定する
        $goal = EnrollmentGoal::findOrFail($goal->id);
        $policy = new EnrollmentGoalPolicy;

        // Assert: この検証では Action を通していないので目標の行は残っている。
        // findOrFail は見つからなければ例外を投げるので、行の存在は DB 側で確かめる
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id]);
        // だが操作はできない
        $this->assertNull($goal->enrollment, '論理削除された親は belongsTo から取得できない');
        $this->assertFalse($policy->update($student, $goal));
        $this->assertFalse($policy->delete($student, $goal));
        $this->assertFalse($policy->markAchieved($student, $goal));
        $this->assertFalse($policy->unmarkAchieved($student, $goal));

        // create も false になること。
        // ⚠️ ここだけ判定経路が違うので、他の4つとは別に確認する必要がある。
        //    受講登録詳細は withTrashed 付きで開けるため、画面は論理削除済みの
        //    Enrollment インスタンスを持っている。それを直接渡されると user_id が読めてしまい、
        //    Policy 側で trashed() を見ないと「目標を追加」フォームだけが表示されてしまう。
        $trashedEnrollment = Enrollment::withTrashed()->findOrFail($enrollment->id);
        $this->assertTrue($trashedEnrollment->trashed(), '受講解除は論理削除で表される');
        $this->assertFalse(
            $policy->create($student, $trashedEnrollment),
            '解除済みの受講登録には目標を追加できない(追加フォームを出さない)',
        );
    }

    /**
     * 達成済みかどうかで判定は変わらない(達成済みの目標も編集・削除できる)。
     *
     * 支給 Blade は編集 / 削除ボタンを @if ($goal->achieved_at) の外に置いている
     * (enrollment-goal/_form.blade.php:93,98)ため、達成済みでも両方表示される。
     * 「状態を見るのは Policy ではなく Action」という本プロジェクトの分担(S-B-03)にも合う。
     */
    public function test_policy_does_not_depend_on_achievement_state(): void
    {
        // Arrange: 同じ受講登録に「未達成」と「達成済み」を1件ずつ用意する
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $unachieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $achieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create();
        $policy = new EnrollmentGoalPolicy;

        // Assert: 達成状態が違っても判定は同じ
        foreach ([$unachieved, $achieved] as $goal) {
            $this->assertTrue($policy->update($student, $goal));
            $this->assertTrue($policy->delete($student, $goal));
            $this->assertTrue($policy->markAchieved($student, $goal));
            $this->assertTrue($policy->unmarkAchieved($student, $goal));
        }
    }

    /**
     * create にだけ効く条件を足しても、編集・削除・達成マークは巻き添えで拒否されない。
     *
     * 5 つの ability は所有判定を共有しているが、共有しているのは isOwnerStudent() であって
     * create() そのものではない。ここを create() の呼び出しに戻すと、将来
     * 「1 受講登録あたり N 件まで」のような追加専用の制限を入れた瞬間に、
     * 上限に達した受講生が既存の目標を直せなくなる（過剰拒否）。
     *
     * 匿名サブクラスで create() だけを潰し、その状態を機械的に再現する。
     *
     * ⚠️ この書き方は本リポジトリで最初の1件（decisions #155）。2つの制約がある。
     *    1. EnrollmentGoalPolicy を final class にすると継承できず fatal になる
     *       （Action は final にする慣習だが、Policy には付けないこと）
     *    2. 守れるのは「isOwner() が create() を呼ぶ形に戻された場合」だけ。
     *       上限判定を共有ヘルパ isOwnerStudent() 側に足されると、このテストは緑のまま通る
     *       （そちらは isOwnerStudent() の docblock の警告で防ぐ）
     */
    public function test_create_only_restrictions_do_not_block_other_operations(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        // create だけが false を返す Policy（将来の上限件数などを模す）
        $policy = new class extends EnrollmentGoalPolicy
        {
            public function create(User $user, Enrollment $enrollment): bool
            {
                return false;
            }
        };

        // Assert: 追加だけが拒否され、既存の目標への操作は通る
        $this->assertFalse($policy->create($student, $enrollment));
        $this->assertTrue($policy->update($student, $goal), 'create の制限が update に波及している');
        $this->assertTrue($policy->delete($student, $goal), 'create の制限が delete に波及している');
        $this->assertTrue($policy->markAchieved($student, $goal), 'create の制限が markAchieved に波及している');
        $this->assertTrue($policy->unmarkAchieved($student, $goal), 'create の制限が unmarkAchieved に波及している');
    }

    /**
     * 受講登録の状態(受講中 / 合格 / 不合格)でも判定は変わらない。
     *
     * EnrollmentPolicy は業務操作(受講解除・修了証受領など)で状態を見るが、
     * 個人目標は「過ぎても残る記録」なので状態で締めない(docs/tickets/S-B-05.md §3.3)。
     */
    public function test_policy_does_not_depend_on_enrollment_status(): void
    {
        // Arrange: 同じ受講生で、状態の違う受講登録を3つ作る
        $student = User::factory()->student()->create();
        $policy = new EnrollmentGoalPolicy;

        foreach (['learning', 'passed', 'failed'] as $state) {
            $enrollment = Enrollment::factory()->for($student)->{$state}()->create();
            $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

            // Assert: どの状態でも本人は操作できる
            $this->assertTrue($policy->create($student, $enrollment), "状態 {$state} で create が false になった");
            $this->assertTrue($policy->update($student, $goal), "状態 {$state} で update が false になった");
        }
    }
}
