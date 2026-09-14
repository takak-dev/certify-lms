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
 * 受講生メモの追加(POST /enrollments/{enrollment}/notes)の検証。
 *
 * 認可は S-B-05(個人学習目標)と真逆——コーチと管理者が書き、受講生は閲覧すら拒否される。
 * 誰が追加できて誰ができないかをここで固定する。
 *
 * 手本: tests/Feature/Http/EnrollmentGoal/StoreTest.php(親リソース配下への POST)
 */
class StoreTest extends TestCase
{
    use RefreshDatabase;

    /** 受講中の受講生と、その人の受講登録を1組作る */
    private function learningEnrollment(?Certification $certification = null): Enrollment
    {
        $student = User::factory()->student()->create();
        $certification ??= Certification::factory()->published()->create();

        return Enrollment::factory()->for($student)->for($certification)->learning()->create();
    }

    /** 資格の担当コーチに割り当てる(中間テーブルは ULID 主キーと割当メタ情報を持つ) */
    private function assignCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);
    }

    /** 担当コーチ付きの受講登録を作り、コーチごと返す */
    private function enrollmentWithAssignedCoach(): array
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);

        return [$this->learningEnrollment($certification), $coach];
    }

    public function test_assigned_coach_can_add_note(): void
    {
        // Arrange
        [$enrollment, $coach] = $this->enrollmentWithAssignedCoach();

        // Act
        $response = $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), [
            'body' => "最近チャットの返信が遅れ気味です。\n次回面談で学習時間を確認します。",
        ]);

        // Assert: メモは独立した画面を持たないので、常に親の受講登録詳細へ戻る
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            // 作成者はフォームの値ではなくログイン中の本人になる
            'author_id' => $coach->id,
        ]);
    }

    /**
     * 管理者は担当割り当てが無くても、任意の受講登録にメモを追加できる。
     *
     * 原典「管理者は任意の受講登録にメモを追加できる」「自分自身もコーチと同じフォーマットで
     * メモを残せる」。S-B-05 では管理者に一切の操作権限が無いので、ここが逆になる。
     */
    public function test_admin_can_add_note_to_any_enrollment(): void
    {
        // Arrange: 管理者はこの資格の担当コーチではない
        $enrollment = $this->learningEnrollment();
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->post(route('enrollments.notes.store', $enrollment), [
            'body' => '運営より: 面談回数の残数が少なくなっています。',
        ]);

        // Assert
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_id' => $admin->id,
        ]);
    }

    /**
     * 担当していない資格のコーチは、URL に直接 POST しても拒否される。
     *
     * ⚠️ このテストがこのチケットで最も重要。担当外コーチは受講登録詳細が 403 で開けないため
     *    画面からは辿れないが、このルートは EnrollmentController::show を通らないので
     *    URL を直接叩けば届いてしまう(B-B-09 と同じ IDOR の形)。
     *    EnrollmentNotePolicy::create が担当を見ていないと、ここが通ってしまう。
     */
    public function test_unassigned_coach_cannot_add_note_even_by_posting_directly(): void
    {
        // Arrange: 担当に割り当てられていないコーチ
        $enrollment = $this->learningEnrollment();
        $outsider = User::factory()->coach()->create();

        // Act
        $response = $this->actingAs($outsider)->post(route('enrollments.notes.store', $enrollment), [
            'body' => '担当外からの書き込み',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    /**
     * 受講生は自分の受講登録にもメモを書けない。
     *
     * ルートの role:admin,coach ミドルウェアが先に 403 を返すため、Policy まで届かない
     * (app/Http/Middleware/EnsureUserRole.php:24)。二重の守りになっている。
     */
    public function test_student_cannot_add_note_to_own_enrollment(): void
    {
        // Arrange
        $enrollment = $this->learningEnrollment();

        // Act
        $response = $this->actingAs($enrollment->user)->post(route('enrollments.notes.store', $enrollment), [
            'body' => '受講生本人からの書き込み',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    /** 未ログインはログイン画面へ */
    public function test_guest_is_redirected_to_login(): void
    {
        // Arrange
        $enrollment = $this->learningEnrollment();

        // Act
        $response = $this->post(route('enrollments.notes.store', $enrollment), ['body' => 'ゲスト']);

        // Assert
        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    /** 本文は必須。原典「本文には文字数制限があり、空では追加できない」 */
    public function test_body_is_required(): void
    {
        // Arrange
        [$enrollment, $coach] = $this->enrollmentWithAssignedCoach();

        // Act
        $response = $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), [
            'body' => '',
        ]);

        // Assert
        $response->assertSessionHasErrors('body');
        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    /**
     * 上限は 2000 文字。画面の :maxlength="2000" と揃える
     * (enrollment-note/_list.blade.php:24)。
     *
     * 境界値を両側から固定する——2000 は通り、2001 で落ちる。
     * 片側だけだと max:1999 や max:2001 に書き換えても気付けない。
     */
    public function test_body_accepts_exactly_2000_characters(): void
    {
        // Arrange
        [$enrollment, $coach] = $this->enrollmentWithAssignedCoach();

        // Act
        $response = $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), [
            'body' => str_repeat('あ', 2000),
        ]);

        // Assert
        $response->assertSessionHas('success');
        $this->assertDatabaseCount('enrollment_notes', 1);
    }

    public function test_body_longer_than_2000_characters_is_rejected(): void
    {
        // Arrange
        [$enrollment, $coach] = $this->enrollmentWithAssignedCoach();

        // Act
        $response = $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), [
            'body' => str_repeat('あ', 2001),
        ]);

        // Assert
        $response->assertSessionHasErrors('body');
        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    /**
     * 作成者はフォームから詐称できない。
     *
     * author_id を混ぜて送っても、$fillable が body だけなので捨てられ、
     * Action がログイン中の本人を入れる。
     */
    public function test_author_cannot_be_spoofed_through_the_form(): void
    {
        // Arrange
        [$enrollment, $coach] = $this->enrollmentWithAssignedCoach();
        $someoneElse = User::factory()->coach()->create();

        // Act: 別人の ID を紛れ込ませる
        $response = $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), [
            'body' => 'なりすましの試み',
            'author_id' => $someoneElse->id,
        ]);

        // Assert: 書いた本人が作成者になる
        $response->assertSessionHas('success');
        $this->assertSame($coach->id, EnrollmentNote::first()->author_id);
    }

    /**
     * 受講解除(親の論理削除)済みの受講登録には追加できない(decisions #137)。
     *
     * ⚠️ ここは 403 ではなく 404。ルートに ->withTrashed() を付けていないため、
     *    モデルバインディングが解決できず Policy まで届かない(decisions #132)。
     *    同じ「解除済みお断り」でも、update / destroy は URL に親を含まないので 403 になる
     *    (UpdateTest / DestroyTest 側で固定している)。
     */
    public function test_cannot_add_note_to_unenrolled_enrollment(): void
    {
        // Arrange
        [$enrollment, $coach] = $this->enrollmentWithAssignedCoach();
        $enrollment->delete();

        // Act
        $response = $this->actingAs($coach)->post(
            route('enrollments.notes.store', $enrollment->id),
            ['body' => '解除後の書き込み'],
        );

        // Assert
        $response->assertNotFound();
        $this->assertDatabaseCount('enrollment_notes', 0);
    }
}
