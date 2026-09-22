<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use App\UseCases\Meeting\StoreAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 受講生の面談予約 StoreAction の検証(T-A-02)。
 *
 * 認可(受講生ロール / 自分の Enrollment か)は StoreRequest::authorize() の担当。
 * この Action が保証するのは ——
 *  ① 予約の成立(reserved の Meeting が 1 件できる)と面談回数 1 回分の消費がセットで起きる
 *  ② 残回数 0 / 候補コーチ無し のときは例外(いずれも 409)で、DB に何も残さない
 *  ③ 担当コーチを**負荷の少ない順**に選ぶ(B-A-01 の並び順)
 *  ④ 通知は担当コーチにだけ届く。予約した本人には送らない(decisions #77)
 *
 * ⚠️ 並行予約(UNIQUE 違反で次の候補へ進む)は 1 プロセスでは再現できないため、
 *    既存の MeetingControllerTest が HTTP 経由で担保している。ここでは扱わない。
 */
class StoreActionTest extends TestCase
{
    use RefreshDatabase;

    /** 資格に担当コーチを割り当てる(中間テーブルの主キーは ULID)。 */
    private function attachCoach(Certification $certification, User $coach, User $admin): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }

    /**
     * 予約が成立する状態を一式そろえる。
     *
     * 日時は「次の月曜 10:00」に固定する。onDay(1) = 月曜の稼働と噛み合い、
     * 今日が何曜日でも必ず未来になるため(after:now のバリデーションと同じ前提)。
     *
     * @param int $maxMeetings 受講生に与える面談回数。0 にすると残回数不足の経路を通せる
     *
     * @return array{student: User, coach: User, enrollment: Enrollment, scheduled_at: Carbon}
     */
    private function bookableSetup(int $maxMeetings = 3): array
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => $maxMeetings]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();

        return [
            'student' => $student,
            'coach' => $coach,
            'enrollment' => Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create(),
            'scheduled_at' => now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0),
        ];
    }

    public function test_creates_a_reserved_meeting_for_the_student(): void
    {
        // --- Arrange ---
        ['student' => $student, 'coach' => $coach, 'enrollment' => $enrollment, 'scheduled_at' => $at] = $this->bookableSetup();

        // --- Act ---
        $meeting = app(StoreAction::class)($enrollment, $at, '午後の学習計画について');

        // --- Assert ---
        $this->assertSame(MeetingStatus::Reserved, $meeting->status);
        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'student_id' => $student->id,
            'coach_id' => $coach->id,
            'enrollment_id' => $enrollment->id,
            'topic' => '午後の学習計画について',
        ]);
        // 予約時点のコーチの面談 URL を控える列。後でコーチが URL を変えても過去の面談は影響を受けない
        $this->assertSame($coach->meeting_url, $meeting->meeting_url_snapshot);
    }

    public function test_consumes_one_meeting_quota(): void
    {
        // --- Arrange ---
        ['student' => $student, 'enrollment' => $enrollment, 'scheduled_at' => $at] = $this->bookableSetup();

        // --- Act ---
        $meeting = app(StoreAction::class)($enrollment, $at, '相談したい');

        // --- Assert: consumed(-1) が 1 行積まれ、面談側からも取引を辿れること ---
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::Consumed->value,
            'amount' => -1,
            'related_meeting_id' => $meeting->id,
        ]);
        $this->assertNotNull($meeting->meeting_quota_transaction_id);
    }

    public function test_notifies_the_coach_but_not_the_student(): void
    {
        // --- Arrange ---
        // 予約画面が「予約完了後、コーチに通知メールが届きます」と約束している(decisions #77)
        Notification::fake();
        ['student' => $student, 'coach' => $coach, 'enrollment' => $enrollment, 'scheduled_at' => $at] = $this->bookableSetup();

        // --- Act ---
        app(StoreAction::class)($enrollment, $at, '相談したい');

        // --- Assert ---
        Notification::assertSentTo($coach, MeetingReservedNotification::class);
        Notification::assertNotSentTo($student, MeetingReservedNotification::class);
    }

    public function test_picks_the_coach_with_the_lighter_recent_load(): void
    {
        // --- Arrange ---
        // 同じ時間帯に空いているコーチを 2 人用意し、片方にだけ「過去 30 日の completed」を積む。
        // CoachMeetingLoadService は過去 30 日の完了件数が少ない順に並べるので、
        // 件数を持たない方が選ばれるはず
        $admin = User::factory()->admin()->create();
        $busyCoach = User::factory()->coach()->inProgress()->create(['meeting_url' => 'https://meet.example.com/busy']);
        $freeCoach = User::factory()->coach()->inProgress()->create(['meeting_url' => 'https://meet.example.com/free']);
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $certification = Certification::factory()->published()->create();

        foreach ([$busyCoach, $freeCoach] as $coach) {
            $this->attachCoach($certification, $coach, $admin);
            CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        }

        // busyCoach にだけ直近の完了面談を 2 件積む(別の受講生・別時刻なので予約の邪魔はしない)。
        // ⚠️ 時刻をずらすのは必須 —— meetings には (coach_id, scheduled_at) の UNIQUE があり、
        //    同じコーチに同時刻の面談を 2 件作ると Arrange の時点で落ちる
        $otherStudent = User::factory()->student()->create();
        foreach ([3, 4] as $daysAgo) {
            Meeting::factory()->completed()->forCoach($busyCoach)->forStudent($otherStudent)->create([
                'scheduled_at' => now()->subDays($daysAgo)->startOfHour(),
            ]);
        }

        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $at = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        // --- Act ---
        $meeting = app(StoreAction::class)($enrollment, $at, '相談したい');

        // --- Assert ---
        $this->assertSame($freeCoach->id, $meeting->coach_id);
    }

    public function test_rejects_when_the_student_has_no_quota_left(): void
    {
        // --- Arrange: max_meetings = 0 なので残回数 0。門番(トランザクション外のチェック)を通す ---
        ['enrollment' => $enrollment, 'scheduled_at' => $at] = $this->bookableSetup(maxMeetings: 0);

        // --- Assert ---
        $this->expectException(InsufficientMeetingQuotaException::class);

        // --- Act ---
        app(StoreAction::class)($enrollment, $at, '相談したい');
    }

    public function test_rejects_when_every_coach_is_taken_at_that_time(): void
    {
        // --- Arrange ---
        // 唯一の担当コーチが、その時刻に別の面談を既に持っている状態。
        // ⚠️ 稼働時間外(例: 21:00)ではこの経路に届かない —— validateSlot() が先に
        //    MeetingOutOfAvailabilityException(422) を投げる。「枠には乗っているが満枠」が
        //    409 になる、という decisions #167 の線引きをここで踏む
        ['coach' => $coach, 'enrollment' => $enrollment, 'scheduled_at' => $at] = $this->bookableSetup();
        $otherStudent = User::factory()->student()->create();
        Meeting::factory()->reserved()->forCoach($coach)->forStudent($otherStudent)->create([
            'scheduled_at' => $at,
        ]);

        // --- Assert ---
        $this->expectException(MeetingNoAvailableCoachException::class);

        // --- Act ---
        app(StoreAction::class)($enrollment, $at, '相談したい');
    }

    public function test_leaves_no_trace_when_the_quota_guard_rejects(): void
    {
        // --- Arrange ---
        // 残回数 0 で弾かれたとき、面談も取引も作られていないこと。
        // 「予約は失敗したのに回数だけ減っている」状態を防ぐ
        ['student' => $student, 'enrollment' => $enrollment, 'scheduled_at' => $at] = $this->bookableSetup(maxMeetings: 0);

        // --- Act ---
        try {
            app(StoreAction::class)($enrollment, $at, '相談したい');
        } catch (InsufficientMeetingQuotaException) {
            // 例外が出ること自体は別のテストで検証済み
        }

        // --- Assert ---
        $this->assertDatabaseMissing('meetings', ['enrollment_id' => $enrollment->id]);
        $this->assertDatabaseMissing('meeting_quota_transactions', ['user_id' => $student->id]);
    }
}
