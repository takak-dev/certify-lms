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
 * 受講生メモの削除(DELETE /enrollment-notes/{note})の検証。
 *
 * 削除は物理削除で履歴を残さない(原典「削除すると履歴は残らない」)。
 * 論理削除でないことを assertDatabaseMissing / assertDatabaseCount で固定する。
 *
 * 手本: tests/Feature/Http/EnrollmentGoal/DestroyTest.php
 */
class DestroyTest extends TestCase
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

    public function test_author_coach_can_delete_own_note(): void
    {
        // Arrange
        [$note, $coach, $enrollment] = $this->noteByAssignedCoach();

        // Act
        $response = $this->actingAs($coach)->delete(route('enrollment-notes.destroy', $note));

        // Assert: 消えた本人の画面には戻れないので、親の受講登録詳細へ戻る
        $response->assertRedirect(route('enrollments.show', $enrollment->id));
        $response->assertSessionHas('success');
        // 物理削除。論理削除なら行が残るので、この検査が落ちる
        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
    }

    /** 管理者は他人のメモも削除できる(原典「運用上の不適切記述の是正」) */
    public function test_admin_can_delete_someone_elses_note(): void
    {
        // Arrange
        [$note] = $this->noteByAssignedCoach();
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->delete(route('enrollment-notes.destroy', $note));

        // Assert
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
    }

    /** 同じ資格を担当する別のコーチでも、他人のメモは削除できない */
    public function test_another_assigned_coach_cannot_delete(): void
    {
        // Arrange
        [$note, , , $certification] = $this->noteByAssignedCoach();
        $other = User::factory()->coach()->create();
        $this->assignCoach($certification, $other);

        // Act
        $response = $this->actingAs($other)->delete(route('enrollment-notes.destroy', $note));

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }

    /** 担当していない資格のコーチは、URL を直接叩いても削除できない */
    public function test_unassigned_coach_is_forbidden(): void
    {
        // Arrange
        [$note] = $this->noteByAssignedCoach();
        $outsider = User::factory()->coach()->create();

        // Act
        $response = $this->actingAs($outsider)->delete(route('enrollment-notes.destroy', $note));

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }

    /** 受講生は自分に対して書かれたメモを消せない */
    public function test_student_is_forbidden(): void
    {
        // Arrange
        [$note, , $enrollment] = $this->noteByAssignedCoach();

        // Act
        $response = $this->actingAs($enrollment->user)->delete(route('enrollment-notes.destroy', $note));

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }

    /** 未ログインはログイン画面へ */
    public function test_guest_is_redirected_to_login(): void
    {
        // Arrange
        [$note] = $this->noteByAssignedCoach();

        // Act
        $response = $this->delete(route('enrollment-notes.destroy', $note));

        // Assert
        $response->assertRedirect(route('login'));
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }

    /**
     * 受講解除済みの受講登録のメモは削除できない(decisions #137)。
     *
     * ⚠️ store は 404 だが、こちらは URL に親を含まないので 403 になる。
     * ⚠️ 「操作できない」だけで行は残る(decisions #47)。姉妹機能の個人学習目標は逆に、
     *    受講解除の時点で物理削除される(decisions #135)。同じ受講登録配下でも扱いが違う。
     */
    public function test_cannot_delete_note_whose_enrollment_is_unenrolled(): void
    {
        // Arrange
        [$note, $coach, $enrollment] = $this->noteByAssignedCoach();
        $admin = User::factory()->admin()->create();
        $enrollment->delete();

        // Act & Assert: コーチも管理者も 403
        $this->actingAs($coach)->delete(route('enrollment-notes.destroy', $note))->assertForbidden();
        $this->actingAs($admin)->delete(route('enrollment-notes.destroy', $note))->assertForbidden();

        // Assert: メモの行は残ったまま
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }

    /**
     * 受講解除しても、配下のメモは消えない(decisions #47 / #137)。
     *
     * ⛔ ここが S-B-05 との最大の違い。Enrollment/DestroyAction は目標を物理削除するが
     *    (decisions #135)、メモには触れてはいけない。誤ってメモの削除を足すとここが落ちる。
     */
    public function test_unenrolling_does_not_delete_notes(): void
    {
        // Arrange
        [$note, , $enrollment] = $this->noteByAssignedCoach();

        // Act: 受講生自身が受講解除する(支給の Enrollment/DestroyAction を通す)
        $response = $this->actingAs($enrollment->user)->delete(route('enrollments.destroy', $enrollment));

        // Assert: 受講登録は論理削除され、メモの行はそのまま残る
        $response->assertSessionHas('success');
        $this->assertSoftDeleted('enrollments', ['id' => $enrollment->id]);
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }
}
