<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\User;
use App\UseCases\Meeting\FetchAvailabilityAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 予約画面が呼ぶ空き枠取得 FetchAvailabilityAction の検証(T-A-02)。
 *
 * 空き枠の計算そのものは MeetingAvailabilityService の責務(専用の単体テストがある)。
 * ここで見るのは Action の担当範囲 ——
 *  ① Enrollment から Certification を解決して Service に渡せているか
 *  ② **Carbon のまま**返しているか(ISO8601 への文字列化は Controller の仕事なので、
 *     ここで文字列になっていたら責務の線を越えている)
 */
class FetchAvailabilityActionTest extends TestCase
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

    public function test_returns_slots_as_carbon_for_a_day_the_coach_works(): void
    {
        // --- Arrange ---
        // 次の月曜(onDay(1) = 月曜)の 9:00-18:00 を稼働にする。
        // 「必ず未来の月曜」を選ぶのは、今日が何曜日でも結果が変わらないようにするため
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();

        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $monday = now()->startOfDay()->next(Carbon::MONDAY);

        // --- Act ---
        $slots = app(FetchAvailabilityAction::class)($enrollment, $monday);

        // --- Assert ---
        $this->assertTrue($slots->isNotEmpty(), '稼働日なので空き枠が 1 件以上あるはず');
        // 文字列ではなく Carbon で返っていること。Controller 側の toIso8601String() が成り立つ前提
        $this->assertInstanceOf(Carbon::class, $slots->first()['slot_start']);
        $this->assertInstanceOf(Carbon::class, $slots->first()['slot_end']);
        $this->assertArrayHasKey('available_coach_count', $slots->first());
    }

    public function test_returns_empty_when_the_certification_has_no_coach(): void
    {
        // --- Arrange ---
        // 担当コーチを 1 人も割り当てない資格。空き枠の計算対象が居ない状態
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();

        // --- Act ---
        $slots = app(FetchAvailabilityAction::class)($enrollment, now()->startOfDay()->next(Carbon::MONDAY));

        // --- Assert ---
        $this->assertTrue($slots->isEmpty());
    }
}
