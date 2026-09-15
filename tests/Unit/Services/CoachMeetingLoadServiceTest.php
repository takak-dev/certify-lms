<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Meeting;
use App\Models\User;
use App\Services\CoachMeetingLoadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachMeetingLoadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_selects_coach_with_fewest_completed_in_last_30_days(): void
    {
        [$coachA, $coachB, $coachC] = User::factory()->coach()->count(3)->create();
        $student = User::factory()->student()->create();

        // 過去 30 日以内の completed: A=3 件 / B=1 件 / C=0 件
        foreach ([5, 10, 15] as $daysAgo) {
            Meeting::factory()->completed()->forCoach($coachA)->forStudent($student)->create([
                'scheduled_at' => now()->subDays($daysAgo)->startOfHour(),
            ]);
        }
        Meeting::factory()->completed()->forCoach($coachB)->forStudent($student)->create([
            'scheduled_at' => now()->subDays(10)->startOfHour(),
        ]);
        // C には完了履歴なし

        $selected = app(CoachMeetingLoadService::class)->leastLoadedCoach(collect([$coachA, $coachB, $coachC]));

        $this->assertSame($coachC->id, $selected->id);
    }

    /**
     * 「割り当てたい順」の**全体**が固定されることを検証する(B-A-01)。
     *
     * 既存の 4 本は leastLoadedCoach() 経由で先頭 1 件しか見ていない。B-A-01 の予約処理は
     * 先頭が並行予約で取られたとき 2 番手・3 番手へ進むため、**2 番手以降の並びが要件**になった。
     * 先頭だけを見るテストでは、->values() の欠落や第 2 キーの消失を検知できない。
     */
    public function test_sort_by_load_returns_all_candidates_in_assignment_order(): void
    {
        // Arrange: 過去 30 日の completed を A=2 件 / B=0 件 / C=1 件 にする。
        [$coachA, $coachB, $coachC] = User::factory()->coach()->count(3)->create();
        $student = User::factory()->student()->create();

        foreach ([5, 10] as $daysAgo) {
            Meeting::factory()->completed()->forCoach($coachA)->forStudent($student)->create([
                'scheduled_at' => now()->subDays($daysAgo)->startOfHour(),
            ]);
        }
        Meeting::factory()->completed()->forCoach($coachC)->forStudent($student)->create([
            'scheduled_at' => now()->subDays(7)->startOfHour(),
        ]);

        // Act
        $sorted = app(CoachMeetingLoadService::class)->sortByLoad(collect([$coachA, $coachB, $coachC]));

        // Assert: 件数の少ない順 B(0) → C(1) → A(2)。添字も 0 から振り直されている。
        $this->assertSame([$coachB->id, $coachC->id, $coachA->id], $sorted->pluck('id')->all());
        $this->assertSame([0, 1, 2], $sorted->keys()->all());
    }

    /**
     * 同数のときの 2 番手以降も ULID 昇順で決定論的に並ぶことを検証する(B-A-01)。
     *
     * 並行予約では全リクエストが同じ順序を得ることが前提になる(順序が入れ替わると、
     * 互いに違うコーチを 1 番手に選んで無駄な衝突が増える)。
     */
    public function test_sort_by_load_breaks_ties_by_ulid_for_every_position(): void
    {
        // Arrange: 3 名とも完了 0 件。
        // ⚠️ **ULID の降順で渡す**。factory の生成順は ULID 昇順なので、そのまま渡すと
        // 第 2 キーが無くても結果が一致してしまい、検証にならない(実測で確認)。
        $coaches = User::factory()->coach()->count(3)->create()->sortByDesc('id')->values();

        // Act
        $sorted = app(CoachMeetingLoadService::class)->sortByLoad($coaches);

        // Assert: 全体が ULID 昇順。
        $this->assertSame($coaches->sortBy('id')->pluck('id')->all(), $sorted->pluck('id')->all());
    }

    public function test_ties_break_by_ulid_ascending(): void
    {
        $coaches = User::factory()->coach()->count(3)->create();

        // 3 名とも完了 0 件 → ULID 昇順で先頭が選ばれる
        $expected = $coaches->sortBy('id')->first();
        $selected = app(CoachMeetingLoadService::class)->leastLoadedCoach($coaches);

        $this->assertSame($expected->id, $selected->id);
    }

    public function test_meetings_older_than_30_days_are_not_counted(): void
    {
        [$coachA, $coachB] = User::factory()->coach()->count(2)->create();
        $student = User::factory()->student()->create();

        // A は 35-39 日前 (30 日窓の外) に completed 5 件 → 集計対象外
        foreach ([31, 33, 36, 40, 45] as $daysAgo) {
            Meeting::factory()->completed()->forCoach($coachA)->forStudent($student)->create([
                'scheduled_at' => now()->subDays($daysAgo)->startOfHour(),
            ]);
        }
        // B は 5 日前に completed 1 件
        Meeting::factory()->completed()->forCoach($coachB)->forStudent($student)->create([
            'scheduled_at' => now()->subDays(5)->startOfHour(),
        ]);

        $selected = app(CoachMeetingLoadService::class)->leastLoadedCoach(collect([$coachA, $coachB]));

        // A は窓外しか持たないため 0 件、B は 1 件 → A が選ばれる(完了数差での勝ち)
        $this->assertSame($coachA->id, $selected->id);
    }

    public function test_only_completed_meetings_are_counted(): void
    {
        [$coachA, $coachB] = User::factory()->coach()->count(2)->create();
        $student = User::factory()->student()->create();

        // A は reserved 3 件 + canceled 3 件 (どちらも集計対象外)
        foreach ([1, 3, 5] as $daysAhead) {
            Meeting::factory()->reserved()->forCoach($coachA)->forStudent($student)->create([
                'scheduled_at' => now()->addDays($daysAhead)->startOfHour(),
            ]);
        }
        foreach ([7, 9, 11] as $daysAgo) {
            Meeting::factory()->canceled()->forCoach($coachA)->forStudent($student)->create([
                'scheduled_at' => now()->subDays($daysAgo)->startOfHour(),
            ]);
        }

        // B は completed 1 件
        Meeting::factory()->completed()->forCoach($coachB)->forStudent($student)->create([
            'scheduled_at' => now()->subDays(5)->startOfHour(),
        ]);

        // A は実質 completed 0 件、B は 1 件 → A が選ばれる(完了数差での勝ち)
        $selected = app(CoachMeetingLoadService::class)->leastLoadedCoach(collect([$coachA, $coachB]));

        $this->assertSame($coachA->id, $selected->id);
    }
}
