<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeetingAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private function attachCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }

    public function test_returns_60min_slots_for_active_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);

        $date = Carbon::parse('2026-06-01'); // Monday (dayOfWeek=1)
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        // 09-10 / 10-11 / 11-12 の 3 枠
        $this->assertCount(3, $slots);
        $this->assertSame('2026-06-01T09:00:00+09:00', $slots->first()['slot_start']->toIso8601String());
        $this->assertSame(1, $slots->first()['available_coach_count']);
    }

    /**
     * キャンセル済みの面談も枠を占有することを検証する(B-A-01・decisions #167)。
     *
     * (coach_id, scheduled_at) UNIQUE は status を問わないので、canceled が残る枠は
     * 物理的に埋まったまま——本人も取り直せない。それを「空き」と表示するのはデータとして誤りで、
     * 押すと 409 になる食い違いが出る。**この 1 本が無いと `whereIn('status', ...)` を戻されても
     * 全テストが緑のまま通る**(5・6 巡目のレビュー指摘)。
     */
    public function test_canceled_meetings_also_occupy_the_slot(): void
    {
        // Arrange: 09:00-12:00 の稼働に、10:00 の **canceled** を 1 件置く。
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $this->attachCoach($certification, $coach);

        $date = Carbon::parse('2026-06-01'); // Monday
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        Meeting::factory()->canceled()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => Carbon::parse('2026-06-01 10:00:00'),
        ]);

        // Act
        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        // Assert: 10:00 は候補から消え、09:00 と 11:00 の 2 枠だけになる。
        $this->assertSame(
            ['09:00', '11:00'],
            $slots->pluck('slot_start')->map(fn (Carbon $s) => $s->format('H:i'))->all(),
        );
    }

    /**
     * 満枠のスロットを validateSlot() が throw しないことを検証する(B-A-01・decisions #167)。
     *
     * 満枠をここで弾くと「予約できない時刻」と「予約できる時刻だが満枠」が同じ 422 になり、
     * 原典が満枠に明記する 409 を返せない。満枠の判定は「担当できるコーチを順に INSERT して
     * 全員 UNIQUE で弾かれたか」に委ねる契約なので、**このゲートは開いたままでなければならない**。
     */
    public function test_validate_slot_accepts_a_fully_booked_slot(): void
    {
        // Arrange: コーチ 1 名の唯一の枠を予約で塞ぐ。
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $this->attachCoach($certification, $coach);

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '10:00:00')->create();
        Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => Carbon::parse('2026-06-01 09:00:00'),
        ]);

        // Act & Assert: 満枠でも throw しない(例外が出れば PHPUnit が失敗させる)。
        app(MeetingAvailabilityService::class)
            ->validateSlot($certification, Carbon::parse('2026-06-01 09:00:00'));

        $this->assertTrue(true);
    }

    /**
     * 60 分が収まらない時刻を validateSlot() が弾くことを検証する(B-A-01)。
     *
     * 稼働 09:00-17:30 なら、画面に出るスロットは 16:00 が最後。17:00 を通すと稼働を 30 分はみ出す。
     * 満枠を見ないようにした結果、ここだけが「枠として存在するか」の砦になっている。
     */
    public function test_validate_slot_rejects_a_slot_that_overflows_availability(): void
    {
        // Arrange
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);

        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '17:30:00')->create();

        // Act & Assert
        $this->expectException(MeetingOutOfAvailabilityException::class);
        app(MeetingAvailabilityService::class)
            ->validateSlot($certification, Carbon::parse('2026-06-01 17:00:00'));
    }

    /**
     * 稼働の開始が毎時 00 分でないコーチが、その格子に無い時刻を提供しないことを検証する(B-A-01)。
     *
     * 稼働 09:30-18:00 のコーチが提供するのは 09:30, 10:30, … であって 10:00 ではない。
     * 候補抽出を SQL の範囲判定(`start_time <= 時刻`)で書くとこのコーチが 10:00 の候補に残り、
     * **画面に出ない時刻が POST で通る**(実測で確認。CLAUDE.md §3-7)。
     */
    public function test_coach_ids_offering_slot_respects_each_coachs_own_grid(): void
    {
        // Arrange: A は 09:00 始まり、B は 09:30 始まり。
        $certification = Certification::factory()->published()->create();
        $coachA = User::factory()->coach()->create();
        $coachB = User::factory()->coach()->create();
        $this->attachCoach($certification, $coachA);
        $this->attachCoach($certification, $coachB);

        CoachAvailability::factory()->forCoach($coachA)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        CoachAvailability::factory()->forCoach($coachB)->onDay(1)->timeRange('09:30:00', '18:00:00')->create();

        $service = app(MeetingAvailabilityService::class);

        // Act & Assert: 10:00 は A だけ、10:30 は B だけが提供する。
        $this->assertSame(
            [$coachA->id],
            $service->coachIdsOfferingSlot($certification, Carbon::parse('2026-06-01 10:00:00')),
        );
        $this->assertSame(
            [$coachB->id],
            $service->coachIdsOfferingSlot($certification, Carbon::parse('2026-06-01 10:30:00')),
        );
    }

    public function test_excludes_existing_reserved_meetings(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $this->attachCoach($certification, $coach);

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        // 10:00 にすでに予約あり
        Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => Carbon::parse('2026-06-01 10:00:00'),
        ]);

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        $times = $slots->map(fn (array $s) => $s['slot_start']->format('H:i'))->all();
        $this->assertEquals(['09:00', '11:00'], $times);
    }

    public function test_excludes_inactive_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '11:00:00')->inactive()->create();

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        $this->assertCount(0, $slots);
    }

    public function test_unions_multiple_coaches_into_available_count(): void
    {
        $certification = Certification::factory()->published()->create();
        $coachA = User::factory()->coach()->create();
        $coachB = User::factory()->coach()->create();
        $this->attachCoach($certification, $coachA);
        $this->attachCoach($certification, $coachB);

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()->forCoach($coachA)->onDay(1)->timeRange('09:00:00', '10:00:00')->create();
        CoachAvailability::factory()->forCoach($coachB)->onDay(1)->timeRange('09:00:00', '10:00:00')->create();

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        $this->assertCount(1, $slots);
        $this->assertSame(2, $slots->first()['available_coach_count']);
    }

    public function test_validate_slot_throws_when_outside_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '10:00:00')->create();

        $this->expectException(MeetingOutOfAvailabilityException::class);

        // 月曜の枠は 09-10 のみ。15:00 は枠外
        app(MeetingAvailabilityService::class)->validateSlot($certification, Carbon::parse('2026-06-01 15:00:00'));
    }

    public function test_validate_slot_succeeds_when_in_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();

        // 例外が起きないことを確認
        app(MeetingAvailabilityService::class)->validateSlot($certification, Carbon::parse('2026-06-01 09:00:00'));
        $this->addToAssertionCount(1);
    }
}
