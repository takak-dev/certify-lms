<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\IndexAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講生本人の面談一覧を取得する IndexAction の検証(T-A-02)。
 *
 * filter (upcoming / past / all) の切り替えと、同じ画面が使う残面談回数の同梱を見る。
 * 認可(自分の一覧しか見られないこと)は IndexRequest::authorize() の担当なので、
 * ここでは「他人の行がクエリに混ざらないか」だけを確認する。
 */
class IndexActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * upcoming / past の両方に該当する面談を 1 件ずつ持つ受講生を作る。
     *
     * upcoming = status:reserved かつ未来(Meeting::scopeUpcoming)
     * past     = status:canceled or completed(Meeting::scopePast。時刻は見ない)
     *
     * @return array{student: User, upcoming: Meeting, past: Meeting}
     */
    private function studentWithBothKinds(): array
    {
        $student = User::factory()->student()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->create();

        return [
            'student' => $student,
            'upcoming' => Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
                'scheduled_at' => now()->addWeek()->startOfHour(),
            ]),
            'past' => Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create([
                'scheduled_at' => now()->subWeek()->startOfHour(),
            ]),
        ];
    }

    public function test_upcoming_returns_only_future_reserved_meetings(): void
    {
        // --- Arrange ---
        ['student' => $student, 'upcoming' => $upcoming] = $this->studentWithBothKinds();

        // --- Act: 'upcoming' は Controller が既定値として渡すフィルタ ---
        $result = app(IndexAction::class)($student, 'upcoming');

        // --- Assert: 未来の reserved だけ。完了済みは出ない ---
        $this->assertCount(1, $result['meetings']);
        $this->assertSame($upcoming->id, $result['meetings']->first()->id);
    }

    public function test_past_returns_only_finished_meetings(): void
    {
        // --- Arrange ---
        ['student' => $student, 'past' => $past] = $this->studentWithBothKinds();

        // --- Act ---
        $result = app(IndexAction::class)($student, 'past');

        // --- Assert: 完了 / キャンセルだけ。予約中は出ない ---
        $this->assertCount(1, $result['meetings']);
        $this->assertSame($past->id, $result['meetings']->first()->id);
    }

    public function test_all_returns_every_meeting_of_the_student(): void
    {
        // --- Arrange ---
        ['student' => $student] = $this->studentWithBothKinds();

        // --- Act ---
        $result = app(IndexAction::class)($student, 'all');

        // --- Assert: 2 件とも出る ---
        $this->assertCount(2, $result['meetings']);
    }

    public function test_is_sorted_descending_by_scheduled_at(): void
    {
        // --- Arrange ---
        // わざと「近い予定」を先に作る。作成順(created_at / id 順)で引かれている場合と
        // scheduled_at の降順で引かれている場合とで、先頭が入れ替わるようにするため。
        // ⚠️ コーチ向けの IndexAsCoachAction は upcoming だけ**昇順**で、受講生側とは並びが違う。
        //    2 つを 1 つの Action にまとめようとすると、このテストと
        //    IndexAsCoachActionTest のどちらかが必ず落ちて気づける
        $student = User::factory()->student()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->create();

        $sooner = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDay()->startOfHour(),
        ]);
        $later = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(10)->startOfHour(),
        ]);

        // --- Act ---
        $result = app(IndexAction::class)($student, 'upcoming');

        // --- Assert: 遠い方が先頭 = 降順 ---
        $this->assertSame($later->id, $result['meetings']->first()->id);
        $this->assertSame($sooner->id, $result['meetings']->last()->id);
    }

    public function test_excludes_other_students_and_returns_remaining_quota(): void
    {
        // --- Arrange ---
        // 同じコーチが担当する「他人の面談」を 1 件混ぜる。student_id での絞り込みが
        // 効いていなければ、この行が結果に紛れ込む
        ['student' => $student] = $this->studentWithBothKinds();
        $otherStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        Meeting::factory()->reserved()->forCoach($coach)->forStudent($otherStudent)->create([
            'scheduled_at' => now()->addWeek()->startOfHour(),
        ]);

        // --- Act ---
        $result = app(IndexAction::class)($student, 'all');

        // --- Assert ---
        $this->assertCount(2, $result['meetings']);
        // 残回数 = max_meetings(3) + 取引の合計(0 件なので 0)。MeetingQuotaService::remaining の定義どおり
        $this->assertSame(3, $result['meetingsRemaining']);
    }
}
