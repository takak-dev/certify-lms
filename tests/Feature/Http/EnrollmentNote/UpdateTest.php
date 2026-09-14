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
 * 受講生メモの編集ページ(GET /enrollment-notes/{note}/edit)と
 * 更新(PATCH /enrollment-notes/{note})の検証。
 *
 * ⚠️ この2本の URL は受講登録を含まない。そのため「解除済みには書けない」(decisions #137 / #158)が
 *    ルート層では弾けず、Policy が $note->enrollment を引いて trashed() を見て止める(decisions #157)。
 *    追加(store)が 404 なのに対し、ここは 403 になる。その違いをこのファイルで固定する。
 *
 * 手本: tests/Feature/Http/EnrollmentGoal/UpdateTest.php
 */
class UpdateTest extends TestCase
{
    use RefreshDatabase;

    /** 資格の担当コーチに割り当てる */
    private function assignCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);
    }

    /**
     * 「担当コーチ + その資格の受講登録 + そのコーチが書いたメモ」を一式作る。
     *
     * @return array{0: EnrollmentNote, 1: User, 2: Enrollment, 3: Certification}
     */
    private function noteByAssignedCoach(): array
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);

        $enrollment = Enrollment::factory()
            ->for(User::factory()->student()->create())
            ->for($certification)
            ->learning()
            ->create();

        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->byAuthor($coach)->create();

        return [$note, $coach, $enrollment, $certification];
    }

    public function test_author_coach_can_open_edit_page(): void
    {
        // Arrange
        [$note, $coach] = $this->noteByAssignedCoach();

        // Act
        $response = $this->actingAs($coach)->get(route('enrollment-notes.edit', $note));

        // Assert: 支給 Blade は本文を textarea の初期値に入れる(edit.blade.php:27)
        $response->assertOk();
        $response->assertSee($note->body);
    }

    public function test_author_coach_can_update_body(): void
    {
        // Arrange
        [$note, $coach, $enrollment] = $this->noteByAssignedCoach();

        // Act
        $response = $this->actingAs($coach)->patch(route('enrollment-notes.update', $note), [
            'body' => '面談で学習計画を組み直しました。来週から演習中心に切り替えます。',
        ]);

        // Assert: メモは独立した画面を持たないので親の受講登録詳細へ戻る
        $response->assertRedirect(route('enrollments.show', $enrollment->id));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => '面談で学習計画を組み直しました。来週から演習中心に切り替えます。',
        ]);
    }

    /**
     * 管理者は他人のメモも更新できる。ただし作成者は付け替わらない。
     *
     * 原典スコープ外「コーチ離任時のメモ自動委譲 / 移管 — 自動的な作成者書き換えは行わない」。
     */
    public function test_admin_can_update_someone_elses_note_without_changing_the_author(): void
    {
        // Arrange
        [$note, $coach] = $this->noteByAssignedCoach();
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->patch(route('enrollment-notes.update', $note), [
            'body' => '運営により表現を修正しました。',
        ]);

        // Assert
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => '運営により表現を修正しました。',
            // 直したのは管理者だが、作成者は元のコーチのまま
            'author_id' => $coach->id,
        ]);
    }

    /**
     * 同じ資格を担当する別のコーチでも、他人のメモは編集できない。
     *
     * 原典「コーチは自分が作成したメモのみ本文を更新できる」。
     * 一覧では読めるが(閲覧のみ)、編集ページにも更新にも進めない。
     */
    public function test_another_assigned_coach_cannot_edit_or_update(): void
    {
        // Arrange: 同じ資格を担当する2人目のコーチ
        [$note, , , $certification] = $this->noteByAssignedCoach();
        $other = User::factory()->coach()->create();
        $this->assignCoach($certification, $other);

        // Act & Assert: 編集ページも更新も拒否される
        $this->actingAs($other)->get(route('enrollment-notes.edit', $note))->assertForbidden();

        $response = $this->actingAs($other)->patch(route('enrollment-notes.update', $note), [
            'body' => '他コーチによる書き換え',
        ]);
        $response->assertForbidden();
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => $note->body]);
    }

    /** 担当していない資格のコーチは、URL を直接叩いても拒否される */
    public function test_unassigned_coach_is_forbidden(): void
    {
        // Arrange
        [$note] = $this->noteByAssignedCoach();
        $outsider = User::factory()->coach()->create();

        // Act & Assert
        $this->actingAs($outsider)->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->actingAs($outsider)
            ->patch(route('enrollment-notes.update', $note), ['body' => '担当外からの書き換え'])
            ->assertForbidden();
    }

    /**
     * 受講生は自分に対して書かれたメモでも触れない。
     * ルートの role:admin,coach ミドルウェアが先に 403 を返す。
     */
    public function test_student_is_forbidden(): void
    {
        // Arrange
        [$note, , $enrollment] = $this->noteByAssignedCoach();

        // Act & Assert
        $this->actingAs($enrollment->user)->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->actingAs($enrollment->user)
            ->patch(route('enrollment-notes.update', $note), ['body' => '受講生による書き換え'])
            ->assertForbidden();
    }

    /** 未ログインはログイン画面へ */
    public function test_guest_is_redirected_to_login(): void
    {
        // Arrange
        [$note] = $this->noteByAssignedCoach();

        // Act & Assert
        $this->get(route('enrollment-notes.edit', $note))->assertRedirect(route('login'));
        $this->patch(route('enrollment-notes.update', $note), ['body' => 'ゲスト'])
            ->assertRedirect(route('login'));
    }

    /**
     * 親の受講登録はフォームから付け替えられない。
     *
     * author_id の詐称(StoreTest)と同格の攻撃。enrollment_id を混ぜて送ると、
     * 通れば「担当資格のメモを、担当外の受講生の画面に移す」ことができてしまう。
     * $fillable が body だけなので捨てられるが、その設計に依存している以上は固定しておく。
     *
     * ⚠️ 余計な項目が来ても**エラーにせず黙って捨てる**(success が返る)。これは Laravel の
     *    $fillable の標準的な振る舞いで、本リポジトリの既存 FormRequest も同じ
     *    (prohibited / exclude を使って弾いている例は 1 つも無い)。
     *    「拒否する」に変えるなら全 FormRequest 横断の方針変更になるので、ここでは揃えた。
     */
    public function test_parent_enrollment_cannot_be_swapped_through_the_form(): void
    {
        // Arrange: 同じコーチが担当する別の受講登録を用意する
        [$note, $coach, $enrollment, $certification] = $this->noteByAssignedCoach();
        $otherEnrollment = Enrollment::factory()
            ->for(User::factory()->student()->create())
            ->for($certification)
            ->learning()
            ->create();

        // Act: 付け替えを試みる
        $response = $this->actingAs($coach)->patch(route('enrollment-notes.update', $note), [
            'body' => '付け替えの試み',
            'enrollment_id' => $otherEnrollment->id,
        ]);

        // Assert: 本文だけ変わり、親は元のまま
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => '付け替えの試み',
            'enrollment_id' => $enrollment->id,
        ]);
    }

    /** 本文は必須。編集フォームの :required="true" と原典の文字数制限に対応する */
    public function test_body_is_required(): void
    {
        // Arrange
        [$note, $coach] = $this->noteByAssignedCoach();

        // Act
        $response = $this->actingAs($coach)->patch(route('enrollment-notes.update', $note), ['body' => '']);

        // Assert: 元の本文が残っている
        $response->assertSessionHasErrors('body');
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => $note->body]);
    }

    /** 上限 2000 文字。追加と同じ境界を更新側でも固定する */
    public function test_body_boundary_is_2000_characters(): void
    {
        // Arrange
        [$note, $coach] = $this->noteByAssignedCoach();

        // Act & Assert: 2000 は通る
        $this->actingAs($coach)
            ->patch(route('enrollment-notes.update', $note), ['body' => str_repeat('あ', 2000)])
            ->assertSessionHas('success');

        // Act & Assert: 2001 は落ちる
        $this->actingAs($coach)
            ->patch(route('enrollment-notes.update', $note), ['body' => str_repeat('い', 2001)])
            ->assertSessionHasErrors('body');

        // 2000 文字のほうが残っている
        $this->assertSame(str_repeat('あ', 2000), $note->fresh()->body);
    }

    /**
     * 受講解除済みの受講登録のメモは更新できない(decisions #137)。
     *
     * ⚠️ ここが store(404)との違い。URL が /enrollment-notes/{note} で親を含まないため
     *    メモ自体は必ず解決し、ルートは通る。止めるのは Policy で、返るのは 403。
     *    ⚠️ メモの行そのものは消さない(decisions #47)。「読めない」と「消える」は別物。
     */
    public function test_cannot_update_note_whose_enrollment_is_unenrolled(): void
    {
        // Arrange
        [$note, $coach, $enrollment] = $this->noteByAssignedCoach();
        $admin = User::factory()->admin()->create();
        $enrollment->delete();

        // Act & Assert: コーチも管理者も 403(404 ではない)
        $this->actingAs($coach)
            ->patch(route('enrollment-notes.update', $note), ['body' => '解除後の書き換え'])
            ->assertForbidden();
        $this->actingAs($admin)
            ->patch(route('enrollment-notes.update', $note), ['body' => '解除後の書き換え'])
            ->assertForbidden();

        // Assert: 本文は変わらず、行も残っている
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => $note->body]);
    }
}
