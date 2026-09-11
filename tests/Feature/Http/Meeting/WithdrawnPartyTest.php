<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Services\UserWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 面談の当事者が退会しても、面談の画面が開き氏名が残ることを固定する。
 *
 * ⭐ このテストがある理由。
 * 退会は User の SoftDelete で表現される(UserWithdrawalService::withdraw)。
 * Meeting::coach() / student() に withTrashed が無いと当事者が null になり、
 * 支給 Blade の `{{ $meeting->coach->name }}`(meeting/index.blade.php:65 ほか)が
 * 「null からプロパティを読む」形になる。PHP は警告を出すだけだが Laravel が例外に変換するため、
 * 画面全体が 500 になる。**例外は退会したときではなく、その面談を誰かが開いたときに初めて出る。**
 *
 * 氏名を残す方針は decisions #46 / #67 / #105。退会処理が書き換えるのは email だけで name は残す。
 */
class WithdrawnPartyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 退会させる。退会の実処理(Service)を直接呼ぶ。
     *
     * 本番の入口は WithdrawAction で、user_status_logs への記録を同じトランザクションで行う。
     * ここで見たいのは「status = Withdrawn + SoftDelete された状態」だけなので監査ログは通さない。
     */
    private function withdraw(User $user): void
    {
        app(UserWithdrawalService::class)->withdraw($user);
    }

    public function test_student_can_open_the_meeting_screens_after_the_coach_withdraws(): void
    {
        // Arrange: 予約済みの面談を1件持つ受講生と、その担当コーチ
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create(['name' => '退会コーチ']);
        $enrollment = Enrollment::factory()->for($student, 'user')->learning()->create();
        $meeting = Meeting::factory()->reserved()
            ->forCoach($coach)->forStudent($student)->forEnrollment($enrollment)
            ->create(['scheduled_at' => now()->addDays(3)]);

        $this->withdraw($coach);

        // Act & Assert: 一覧と詳細のどちらも開け、氏名が残っている
        $this->actingAs($student)->get('/meetings')
            ->assertOk()
            ->assertSee('退会コーチ');
        $this->actingAs($student)->get("/meetings/{$meeting->id}")
            ->assertOk()
            ->assertSee('退会コーチ');
    }

    public function test_coach_can_cancel_a_meeting_whose_student_has_withdrawn(): void
    {
        // Arrange: 退会した受講生の予約をコーチがキャンセルする。
        //          ⚠️ withTrashed を足す前は、MeetingController がキャンセル時に呼ぶ
        //          RefundQuotaAction へ null が渡り TypeError で 500 になっていた
        //          （キャンセル自体が成立しなかった）。decisions #105 の副次的な効果
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->learning()->create();
        $meeting = Meeting::factory()->reserved()
            ->forCoach($coach)->forStudent($student)->forEnrollment($enrollment)
            ->create(['scheduled_at' => now()->addDays(3)]);

        $this->withdraw($student);

        // Act
        $this->actingAs($coach)
            ->post("/meetings/{$meeting->id}/cancel")
            ->assertRedirect(route('meetings.show', $meeting->id));

        // Assert: 状態が変わり、面談回数の返却も 1 行積まれる（退会者名義で残る）
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        $this->assertSame(1, MeetingQuotaTransaction::query()
            ->where('user_id', $student->id)
            ->where('type', MeetingQuotaTransactionType::Refunded->value)
            ->count());
    }

    public function test_coach_can_open_the_meeting_screens_after_the_student_withdraws(): void
    {
        // Arrange: 立場を入れ替えて同じことを確かめる
        $student = User::factory()->student()->inProgress()->create(['name' => '退会受講生']);
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->learning()->create();
        $meeting = Meeting::factory()->reserved()
            ->forCoach($coach)->forStudent($student)->forEnrollment($enrollment)
            ->create(['scheduled_at' => now()->addDays(3)]);

        $this->withdraw($student);

        // Act & Assert
        $this->actingAs($coach)->get('/coach/meetings')
            ->assertOk()
            ->assertSee('退会受講生');
        $this->actingAs($coach)->get("/meetings/{$meeting->id}")
            ->assertOk()
            ->assertSee('退会受講生');
    }
}
