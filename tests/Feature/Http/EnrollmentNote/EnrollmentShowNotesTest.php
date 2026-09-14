<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentNote;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 受講登録詳細(GET /enrollments/{enrollment})に埋め込まれたメモ一覧の検証。
 *
 * メモは単独の一覧画面を持たず、この画面の中にだけ表示される(原典)。
 * 「誰に何が見えるか」と「誰に操作ボタンが出るか」は別の話なので、両方を分けて固定する。
 *
 * ⛔ このチケットの核心は「受講生本人にはセクション自体が現れない」。
 *    支給 Blade は enrollment/show.blade.php:251 の @can('viewAny', ...) でカードごと囲んでおり、
 *    Policy::viewAny が false を返すと見出しごと消える。それをここで確かめる。
 *
 * ⚠️ このファイルは支給 Blade の文言リテラル(「コーチメモ」「まだメモがありません。」)に依存している。
 *    画面は変更不可の支給物なので固定値として扱ってよいが、支給 Blade が差し替わったら
 *    ここが落ちる。落ちたときは「実装が壊れた」ではなく「画面が変わった」を先に疑うこと。
 *    手本の EnrollmentShowGoalsTest も同じ形(「まだ目標が登録されていません。」)。
 *
 * 手本: tests/Feature/Http/EnrollmentGoal/EnrollmentShowGoalsTest.php
 */
class EnrollmentShowNotesTest extends TestCase
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
     * 「担当コーチ + その資格の受講登録」を作る。
     *
     * @return array{0: Enrollment, 1: User}
     */
    private function enrollmentWithAssignedCoach(): array
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);

        $enrollment = Enrollment::factory()
            ->for(User::factory()->student()->create())
            ->for($certification)
            ->learning()
            ->create();

        return [$enrollment, $coach];
    }

    /**
     * ⛔ 受講生本人には、メモのセクション自体が現れない。
     *
     * 原典「受講生: 自分の受講登録詳細画面にメモのセクション自体が現れない」。
     * 本文が見えないだけでは不十分で、見出し(カード)ごと出ないことまで確かめる。
     */
    public function test_student_does_not_see_the_note_section_at_all(): void
    {
        // Arrange: 自分に対して書かれたメモが 1 件ある状態
        [$enrollment, $coach] = $this->enrollmentWithAssignedCoach();
        EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create([
            'body' => '受講生には見えてはいけない観察メモ',
        ]);

        // Act: 受講生本人が自分の受講登録詳細を開く
        $response = $this->actingAs($enrollment->user)->get(route('enrollments.show', $enrollment));

        // Assert: 画面自体は開けるが、メモの痕跡が一切無い
        $response->assertOk();
        $response->assertDontSee('コーチメモ');                          // カードの見出し
        $response->assertDontSee('受講生には見えてはいけない観察メモ');      // 本文
        $response->assertDontSee('まだメモがありません。');                // 0 件メッセージも出ない
        $response->assertDontSee(route('enrollments.notes.store', $enrollment), false);  // 追加フォーム
    }

    /**
     * 担当コーチには、自分のメモに編集 / 削除の導線が出る。
     */
    public function test_assigned_coach_sees_controls_on_own_note(): void
    {
        // Arrange
        [$enrollment, $coach] = $this->enrollmentWithAssignedCoach();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create([
            'body' => '自分が書いた観察メモ',
        ]);

        // Act
        $response = $this->actingAs($coach)->get(route('enrollments.show', $enrollment));

        // Assert: カード・本文・追加フォーム・編集 / 削除の導線がすべて出る
        $response->assertOk();
        $response->assertSee('コーチメモ');
        $response->assertSee('自分が書いた観察メモ');
        $response->assertSee(route('enrollments.notes.store', $enrollment), false);
        $response->assertSee(route('enrollment-notes.edit', $note), false);
        $response->assertSee(route('enrollment-notes.destroy', $note), false);
    }

    /**
     * 他コーチのメモは「読めるがボタンが出ない」。原典「他コーチのメモは閲覧のみ」。
     *
     * ⚠️ 支給 Blade の @can が囲んでいるのはボタンだけで、行と本文は外にある
     *    (enrollment-note/_list.blade.php:46,51)。だから update が false でも本文は読める。
     *    「見えない」と「操作できない」を取り違えないよう、1 つの画面で両方を確かめる。
     */
    public function test_other_coachs_note_is_visible_but_has_no_controls(): void
    {
        // Arrange: 同じ資格を担当するコーチ2名が、それぞれ1件ずつ書いた状態
        [$enrollment, $coach] = $this->enrollmentWithAssignedCoach();
        $other = User::factory()->coach()->create();
        $this->assignCoach($enrollment->certification, $other);

        $mine = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create([
            'body' => '自分のメモ',
        ]);
        $theirs = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($other)->create([
            'body' => '他コーチのメモ',
        ]);

        // Act
        $response = $this->actingAs($coach)->get(route('enrollments.show', $enrollment));

        // Assert: 両方の本文と投稿者名は見える
        $response->assertOk();
        $response->assertSee('自分のメモ');
        $response->assertSee('他コーチのメモ');
        $response->assertSee($other->name);

        // Assert: 自分のメモにだけ編集 / 削除の導線が出る
        $response->assertSee(route('enrollment-notes.edit', $mine), false);
        $response->assertDontSee(route('enrollment-notes.edit', $theirs), false);
        $response->assertDontSee(route('enrollment-notes.destroy', $theirs), false);
    }

    /**
     * 管理者は担当割り当てが無くても全メモを見られ、全メモに導線が出る。
     *
     * ⚠️ S-B-05(個人学習目標)では管理者に操作ボタンが一切出ない。ここが真逆になる。
     */
    public function test_admin_sees_controls_on_every_note(): void
    {
        // Arrange
        [$enrollment, $coach] = $this->enrollmentWithAssignedCoach();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create([
            'body' => 'コーチが書いたメモ',
        ]);
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->get(route('enrollments.show', $enrollment));

        // Assert: 他人のメモにも編集 / 削除が出る
        $response->assertOk();
        $response->assertSee('コーチが書いたメモ');
        $response->assertSee(route('enrollments.notes.store', $enrollment), false);
        $response->assertSee(route('enrollment-notes.edit', $note), false);
        $response->assertSee(route('enrollment-notes.destroy', $note), false);
    }

    /**
     * 一覧は新しい順(enrollment-note/_list.blade.php:9 の orderByDesc('created_at'))。
     *
     * 並び順を支給 Blade が自分で指定しているので、こちら側の実装では変えられない。
     * それでも固定するのは、後から notes() リレーションに並び順を足したときに
     * 食い違いが起きないようにするため。
     */
    public function test_notes_are_listed_newest_first(): void
    {
        // Arrange: 作成日時を明示的にずらす(同一秒だと順序が不定になるため)
        [$enrollment, $coach] = $this->enrollmentWithAssignedCoach();
        EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create([
            'body' => '古いメモ',
            'created_at' => now()->subDays(3),
        ]);
        EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create([
            'body' => '新しいメモ',
            'created_at' => now(),
        ]);

        // Act
        $response = $this->actingAs($coach)->get(route('enrollments.show', $enrollment));

        // Assert: 画面に現れる順番そのものを検証する
        $response->assertSeeInOrder(['新しいメモ', '古いメモ']);
    }

    /** メモが 0 件のとき、コーチには専用の文面が出る(受講生には何も出ない) */
    public function test_empty_message_is_shown_to_coach_only(): void
    {
        // Arrange: メモを 1 件も作らない
        [$enrollment, $coach] = $this->enrollmentWithAssignedCoach();

        // Act & Assert: コーチには 0 件メッセージが出る
        $this->actingAs($coach)
            ->get(route('enrollments.show', $enrollment))
            ->assertSee('まだメモがありません。');

        // Act & Assert: 受講生にはカードごと出ないので、この文面も出ない
        $this->actingAs($enrollment->user)
            ->get(route('enrollments.show', $enrollment))
            ->assertDontSee('まだメモがありません。');
    }

    /**
     * ⛔ 退会したコーチが書いたメモも、氏名をそのまま表示し続ける(decisions #46。面談1 で確定)。
     *
     * ⚠️ 支給 Blade は `$note->author?->name ?? '不明'`(enrollment-note/_list.blade.php:42)と
     *    null 安全に書かれているため、EnrollmentNote::author() の ->withTrashed() を外しても
     *    500 にはならず、静かに「不明」と表示されるだけになる。
     *    画面が壊れない分だけ気付きにくいので、機械に見張らせる。
     *
     * 退会処理は email だけを匿名化し、氏名は残す設計(UserWithdrawalService)。
     */
    public function test_withdrawn_coachs_name_is_still_shown_on_the_note(): void
    {
        // Arrange: コーチがメモを書いたあとに退会(論理削除)する
        [$enrollment, $coach] = $this->enrollmentWithAssignedCoach();
        EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create([
            'body' => '退会したコーチが残したメモ',
        ]);
        $coachName = $coach->name;
        $coach->delete();

        // Act: 別のコーチ(現任)が同じ受講登録を開く
        $viewer = User::factory()->coach()->create();
        $this->assignCoach($enrollment->certification, $viewer);
        $response = $this->actingAs($viewer)->get(route('enrollments.show', $enrollment));

        // Assert: 本文も、退会したコーチの氏名も出る
        $response->assertOk();
        $response->assertSee('退会したコーチが残したメモ');
        $response->assertSee($coachName);
        $response->assertDontSee('不明');
    }

    /** 担当していない資格のコーチは、受講登録詳細そのものに到達できない */
    public function test_unassigned_coach_cannot_reach_the_screen_at_all(): void
    {
        // Arrange
        [$enrollment] = $this->enrollmentWithAssignedCoach();
        $outsider = User::factory()->coach()->create();

        // Act & Assert: EnrollmentPolicy::view が弾く(メモ以前の問題)
        $this->actingAs($outsider)
            ->get(route('enrollments.show', $enrollment))
            ->assertForbidden();
    }

    /**
     * 受講解除後は、担当コーチが詳細を開いてもメモのカードが出ない(decisions #47 / #137)。
     *
     * ⚠️ 画面自体は開ける。enrollments.show のルートには ->withTrashed() が付いているので、
     *    解除済みの受講登録も表示できる。
     *    それでもメモが出ないのは Policy::viewAny が trashed() を見ているから。
     * ⚠️ メモの行そのものは残っている(「見えない」と「消えた」は別物)。
     */
    public function test_notes_disappear_from_the_screen_after_unenrolling(): void
    {
        // Arrange
        [$enrollment, $coach] = $this->enrollmentWithAssignedCoach();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create([
            'body' => '解除後は見えなくなるメモ',
        ]);

        // Act: 受講生が受講解除したあとで、コーチが詳細を開く
        $this->actingAs($enrollment->user)->delete(route('enrollments.destroy', $enrollment));
        $response = $this->actingAs($coach)->get(route('enrollments.show', $enrollment->id));

        // Assert: 画面は開けるが、メモのカードごと出ない
        $response->assertOk();
        $response->assertDontSee('コーチメモ');
        $response->assertDontSee('解除後は見えなくなるメモ');

        // Assert: 行は残っている
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }
}
