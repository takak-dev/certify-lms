<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\UseCases\Enrollment\DestroyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講生が受講解除したあとも、その受講登録の面談の画面が開けることを確かめる(decisions #285)。
 *
 * 受講解除(Enrollment\DestroyAction)は受講登録を論理削除するだけで、面談は残る。
 * Meeting::enrollment() が解除済みの受講登録を引けないと、面談の一覧・詳細
 * (meeting/index.blade.php・meeting/show.blade.php・meeting/coach/index.blade.php の
 * `$meeting->enrollment->certification->name`)が資格名を読めず 500 になっていた。
 * 解除後も、面談のキャンセル(面談回数が戻る)は後片付けとして許す(本人承認)。
 */
class UnenrolledEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_open_meeting_screens_after_unenrolling(): void
    {
        // Arrange: 面談を 1 件持つ受講登録を、受講生本人が受講解除する。
        ['student' => $student, 'meeting' => $meeting] = $this->meetingOfUnenrolledEnrollment();

        // Act & Assert: 受講生の面談一覧と詳細が、500 にならず開け、面談の資格名が出る。
        //   資格名は受講解除の前に決めた値と比べる(直したリレーションから取ると、そのリレーション自体を検証できない)。
        $this->actingAs($student)->get(route('meetings.index'))->assertOk()->assertSee('解除テスト用の資格');
        $this->actingAs($student)->get(route('meetings.show', $meeting))->assertOk()->assertSee('解除テスト用の資格');
    }

    public function test_coach_meeting_index_opens_after_student_unenrolls(): void
    {
        // Arrange: 同上。
        ['coach' => $coach] = $this->meetingOfUnenrolledEnrollment();

        // Act & Assert: コーチの面談一覧が、500 にならず開け、面談の資格名が出る。
        $this->actingAs($coach)->get(route('coach.meetings.index'))->assertOk()->assertSee('解除テスト用の資格');
    }

    public function test_student_can_cancel_reserved_meeting_after_unenrolling(): void
    {
        // Arrange: 予約済みの面談を持つ受講登録を、受講生本人が受講解除する。
        //   受講解除後は書き足せない(decisions #285)が、面談のキャンセルは例外として許す(本人承認)。
        //   止めると受講生は面談回数を失い、コーチの枠も埋まったままになるため。
        ['student' => $student, 'meeting' => $meeting] = $this->meetingOfUnenrolledEnrollment();

        // Act: 受講生が面談の詳細画面からキャンセルする。
        $response = $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        // Assert: キャンセルが成立し、面談回数の返却が 1 行積まれる。
        $response->assertRedirect(route('meetings.show', $meeting->id));
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        $this->assertSame(1, MeetingQuotaTransaction::query()
            ->where('user_id', $student->id)
            ->where('type', MeetingQuotaTransactionType::Refunded->value)
            ->count());
    }

    /**
     * 予約済みの面談を 1 件持つ受講登録を作り、受講生本人が受講解除する。
     * 面談の作り方は Meeting/WithdrawnPartyTest と同じ。
     *
     * @return array{coach: User, student: User, meeting: Meeting}
     */
    private function meetingOfUnenrolledEnrollment(): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create(['name' => '解除テスト用の資格']);
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $meeting = Meeting::factory()->reserved()
            ->forCoach($coach)->forStudent($student)->forEnrollment($enrollment)
            ->create(['scheduled_at' => now()->addDays(3)]);

        // 受講解除は本物の処理で行う。
        app(DestroyAction::class)($enrollment);

        return ['coach' => $coach, 'student' => $student, 'meeting' => $meeting];
    }
}
