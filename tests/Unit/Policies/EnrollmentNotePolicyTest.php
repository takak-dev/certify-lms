<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use App\Policies\EnrollmentNotePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 受講生メモの認可ポリシーを単体で検証する。**このチケットの本体**。
 *
 * ⚠️ 姉妹チケット S-B-05(個人学習目標)と権限が真逆。あちらは「受講生本人だけが操作でき
 *    管理者にも特権が無い」、こちらは「コーチ / 管理者が操作し受講生は閲覧すらできない」。
 *    片方をコピーして書き換え忘れると、どちらかのテストが落ちて気付ける形にしてある。
 *
 * 手本: tests/Unit/Policies/EnrollmentGoalPolicyTest.php(同じ受講登録配下の Policy テスト) /
 *       tests/Unit/Policies/EnrollmentPolicyTest.php(担当コーチの作り方)
 */
class EnrollmentNotePolicyTest extends TestCase
{
    use RefreshDatabase;

    private EnrollmentNotePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new EnrollmentNotePolicy;
    }

    /**
     * 担当コーチは、自分の書いたメモに対して4つの ability すべてを行える。
     *
     * viewAny / create は親の受講登録を、update / delete はメモそのものを対象にする
     * (メモを追加する時点ではまだメモの行が存在しないため、判定対象が親になる)。
     */
    public function test_assigned_coach_can_do_everything_to_own_note(): void
    {
        // Arrange: 担当コーチ + その資格の受講登録 + 自分が書いたメモ
        ['coach' => $coach, 'enrollment' => $enrollment] = $this->makeAssignedCoachContext();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create();

        // Assert
        $this->assertTrue($this->policy->viewAny($coach, $enrollment));
        $this->assertTrue($this->policy->create($coach, $enrollment));
        $this->assertTrue($this->policy->update($coach, $note));
        $this->assertTrue($this->policy->delete($coach, $note));
    }

    /**
     * 担当コーチは「他コーチのメモ」を書き換えられない。ただし閲覧はできる。
     *
     * ⚠️ ここが支給 Blade の作りと対応している。update / delete が false でも
     *    viewAny が true なら一覧には残り本文も読める(_list.blade.php:46,51 が囲んでいるのは
     *    ボタンだけで、行と本文は @can の外)。原典「他コーチのメモは閲覧のみ」はこの形で実現される。
     */
    public function test_assigned_coach_can_read_but_not_edit_another_coachs_note(): void
    {
        // Arrange: 同じ資格を担当するコーチ2名。メモを書いたのは other のほう
        ['coach' => $coach, 'enrollment' => $enrollment, 'certification' => $certification, 'admin' => $admin]
            = $this->makeAssignedCoachContext();
        $other = User::factory()->coach()->create();
        $this->assignCoach($other, $certification, $admin);
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($other)->create();

        // Assert: 読めるが書き換えられない
        $this->assertTrue($this->policy->viewAny($coach, $enrollment));
        $this->assertFalse($this->policy->update($coach, $note));
        $this->assertFalse($this->policy->delete($coach, $note));
    }

    /**
     * 担当コーチは「管理者が書いたメモ」も書き換えられない。
     *
     * 原典「コーチは自分が作成したメモのみ本文を更新できる」。作成者が誰であれ、
     * 自分以外のメモには触れない。
     */
    public function test_assigned_coach_cannot_edit_admins_note(): void
    {
        // Arrange
        ['coach' => $coach, 'enrollment' => $enrollment, 'admin' => $admin] = $this->makeAssignedCoachContext();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($admin)->create();

        // Assert
        $this->assertFalse($this->policy->update($coach, $note));
        $this->assertFalse($this->policy->delete($coach, $note));
    }

    /**
     * 管理者は越境してすべてを行える。コーチが書いたメモも編集・削除できる。
     *
     * ⚠️ S-B-05(個人学習目標)では管理者に一切の操作権限が無い。ここが真逆になる分かれ目。
     *    原典「運用上の不適切記述の是正やコーチ離任時のメモ管理を一元的に扱いたい」。
     */
    public function test_admin_can_do_everything_including_other_peoples_notes(): void
    {
        // Arrange: 管理者は資格の担当に割り当てられていない(割り当て無しでも通ることを固定する)
        ['coach' => $coach, 'enrollment' => $enrollment, 'admin' => $admin] = $this->makeAssignedCoachContext();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create();

        // Assert
        $this->assertTrue($this->policy->viewAny($admin, $enrollment));
        $this->assertTrue($this->policy->create($admin, $enrollment));
        $this->assertTrue($this->policy->update($admin, $note));
        $this->assertTrue($this->policy->delete($admin, $note));
    }

    /**
     * 担当していない資格のコーチは、4つとも拒否される。
     *
     * ⚠️ 「受講登録詳細が 403 だから要らない」ではない。メモの追加は
     *    POST /enrollments/{enrollment}/notes という別ルートで、EnrollmentController::show を通らない。
     *    ここで担当を見ていないと、画面を経由せず URL に直接 POST するだけで書けてしまう
     *    (B-B-09 と同じ IDOR の形)。Feature テスト側でも実際に POST して 403 を確認する。
     */
    public function test_unassigned_coach_is_denied_everything(): void
    {
        // Arrange: メモは担当コーチが書いたもの。それを担当外コーチが触ろうとする
        ['coach' => $coach, 'enrollment' => $enrollment] = $this->makeAssignedCoachContext();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create();
        $outsider = User::factory()->coach()->create();

        // Assert
        $this->assertFalse($this->policy->viewAny($outsider, $enrollment));
        $this->assertFalse($this->policy->create($outsider, $enrollment));
        $this->assertFalse($this->policy->update($outsider, $note));
        $this->assertFalse($this->policy->delete($outsider, $note));
    }

    /**
     * 担当を外れたコーチは、自分が書いたメモでも書き換えられない。
     *
     * ⚠️ 暫定判断(面談3 Q53 で確認予定)。原典が2か所で食い違っているため厳しい側に倒した——
     *    HTTP 表の認可欄は「作成者本人 / 管理者」(担当条件なし)だが、アクセス制御は
     *    「担当していない資格の受講登録に対するコーチのメモ操作は拒否」と書いている。
     *    メモの更新 / 削除の URL は親を含まないため、担当を外れても URL では届いてしまう。
     *    PM 回答で緩める場合は、Policy の && を 1 つ外してこのテストを書き換える。
     */
    public function test_coach_loses_access_to_own_note_after_being_unassigned(): void
    {
        // Arrange: 担当コーチが自分のメモを1件書いたあとで、担当割り当てを解除する
        ['coach' => $coach, 'enrollment' => $enrollment, 'certification' => $certification]
            = $this->makeAssignedCoachContext();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create();

        // 割り当て前は操作できることを確かめておく(解除が効いたことを言うための対照)
        $this->assertTrue($this->policy->update($coach, $note));

        // Act: 担当を外す
        CertificationCoachAssignment::where('certification_id', $certification->id)
            ->where('user_id', $coach->id)
            ->delete();

        // ⚠️ 直前の判定で certification.coaches を読み込み済みなので、
        //    そのまま使うと古い結果が残る(loadMissing は再読込しない)。取り直す。
        $note = $note->fresh();

        // Assert: 作成者本人であっても拒否される
        $this->assertFalse($this->policy->update($coach, $note));
        $this->assertFalse($this->policy->delete($coach, $note));
    }

    /**
     * 受講生本人は4つとも拒否される。**閲覧すらできない**のがこの機能の核心。
     *
     * viewAny が false になることで、受講登録詳細からメモのカードごと消える
     * (enrollment/show.blade.php:251)。原典「メモは業務記録であり、受講生に見えると
     * 素直な観察ができなくなる」。
     */
    public function test_owner_student_is_denied_everything_including_viewing(): void
    {
        // Arrange: 自分の受講登録・自分に対して書かれたメモ
        ['coach' => $coach, 'enrollment' => $enrollment, 'student' => $student] = $this->makeAssignedCoachContext();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create();

        // Assert
        $this->assertFalse($this->policy->viewAny($student, $enrollment));
        $this->assertFalse($this->policy->create($student, $enrollment));
        $this->assertFalse($this->policy->update($student, $note));
        $this->assertFalse($this->policy->delete($student, $note));
    }

    /**
     * 受講解除(親の論理削除)後は、担当コーチも管理者もメモを読めず書けない(decisions #137)。
     *
     * ⚠️ 管理者も例外にしない。#137 は「読むことも書くこともできない」と役割を分けずに決めている。
     *    viewAny を一律 false にする以上、update だけ管理者に通すと
     *    「カードは見えないのに URL を打てば直せる」という食い違いになる。
     *
     * ⚠️ メモの行そのものは残る(decisions #47。S-B-05 の目標は逆に物理削除される)。
     *    「読めない」と「消える」は別物。ここを取り違えないよう、行が残ることも同時に検査する。
     */
    public function test_nobody_can_touch_notes_after_the_enrollment_is_unenrolled(): void
    {
        // Arrange
        ['coach' => $coach, 'enrollment' => $enrollment, 'admin' => $admin] = $this->makeAssignedCoachContext();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create();

        // Act: 受講解除(Enrollment は SoftDeletes)
        $enrollment->delete();

        // ⚠️ ここでは $note->fresh() は要らない。この test は Act より前に一度も
        //    $note->enrollment を読んでいないため、Policy が初めて触れた時点で DB から引き直され、
        //    論理削除済みの親が deleted_at 付きで返る(EnrollmentNote::enrollment() は withTrashed。
        //    decisions #157)。Policy はそれを trashed() で落とす。

        // Assert: 読めない(カードが出ない) / 書けない
        $this->assertFalse($this->policy->viewAny($coach, $enrollment));
        $this->assertFalse($this->policy->create($coach, $enrollment));
        $this->assertFalse($this->policy->update($coach, $note));
        $this->assertFalse($this->policy->delete($coach, $note));

        $this->assertFalse($this->policy->viewAny($admin, $enrollment));
        $this->assertFalse($this->policy->create($admin, $enrollment));
        $this->assertFalse($this->policy->update($admin, $note));
        $this->assertFalse($this->policy->delete($admin, $note));

        // Assert: それでもメモの行は残っている(decisions #47)
        $this->assertNotNull(EnrollmentNote::find($note->id));
    }

    /**
     * ⛔ 解除済みの親を setRelation() で手で配られても、拒否は覆らない。
     *
     * レビューで指摘された穴の回帰テスト。当初 canManageNote() は
     * 「belongsTo が論理削除済みの親に null を返す」ことだけを見ており、
     * setRelation() で親を手で配られると素通りした(管理者が解除済みのメモを更新できた。実測で確認)。
     *
     * ⚠️ これは机上の話ではない。S-B-05 の Enrollment/ShowAction.php:32-35 に
     *    「@can の N+1 を減らすため setRelation で親を配りたくなる」という誘惑が
     *    コメントで戒められている。コメントだけで止めているものは、いつか踏まれる。
     *
     * いまは EnrollmentNote::enrollment() に withTrashed を付け、Policy が trashed() を
     * 明示的に見る形にした(decisions #157)。
     *
     * ⚠️ 「読み込み方に一切左右されない」とまでは言えない。**解除より前に**読み込まれた
     *    インスタンスは deleted_at が null のままなので trashed() が false になり、通る(実測)。
     *    現状その経路は無い——ルートモデルバインディングが毎リクエストでメモを引き直し、
     *    notes.enrollment を先読みする箇所はリポジトリに 1 件も無い。毎回引き直す実装は
     *    メモ件数 × 2 のクエリ増になるので採らなかった(decisions #157)。
     *    この test が固定するのは「解除後に配られた場合」だけである。
     */
    public function test_a_trashed_parent_handed_in_by_set_relation_is_still_rejected(): void
    {
        // Arrange
        ['coach' => $coach, 'enrollment' => $enrollment, 'admin' => $admin] = $this->makeAssignedCoachContext();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create();
        $enrollment->delete();

        // Act: 解除済みの親インスタンスを手で配る(DB を引き直させない)
        $note->setRelation('enrollment', $enrollment);

        // Assert: それでも拒否される
        $this->assertFalse($this->policy->update($coach, $note));
        $this->assertFalse($this->policy->delete($coach, $note));
        $this->assertFalse($this->policy->update($admin, $note));
        $this->assertFalse($this->policy->delete($admin, $note));
    }

    /**
     * 「担当コーチ / その資格 / 受講生 / 受講登録 / 管理者」を一式作る。
     *
     * 担当コーチの作り方は certification_coach_assignments への直接 insert
     * (手本: EnrollmentGoalPolicyTest / EnrollmentPolicyTest)。
     *
     * @return array{coach: User, student: User, admin: User, certification: Certification, enrollment: Enrollment}
     */
    private function makeAssignedCoachContext(): array
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certification, $admin);

        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();

        return compact('coach', 'student', 'admin', 'certification', 'enrollment');
    }

    /**
     * コーチを資格の担当に割り当てる。
     */
    private function assignCoach(User $coach, Certification $certification, User $admin): void
    {
        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
    }
}
