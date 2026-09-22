<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\Models\User;
use App\UseCases\Meeting\UpsertMemoAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 担当コーチによる面談メモの作成・更新 UpsertMemoAction の検証(T-A-02)。
 *
 * 認可(担当コーチ本人か)は UpsertMemoRequest::authorize() の担当。
 * この Action が保証するのは 2 つだけ ——
 *  ① 1 面談 : 1 メモ(2 回目は新規作成ではなく更新になる)
 *  ② メモを残してよい状態のガード(canceled には残せない)
 */
class UpsertMemoActionTest extends TestCase
{
    use RefreshDatabase;

    /** 状態を指定して面談を 1 件作る。コーチ / 受講生の中身はこのテストでは問わない。 */
    private function meeting(string $state): Meeting
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        return Meeting::factory()->{$state}()->forCoach($coach)->forStudent($student)->create();
    }

    public function test_creates_a_memo_when_none_exists(): void
    {
        // --- Arrange: まだメモが無い予約中の面談 ---
        $meeting = $this->meeting('reserved');

        // --- Act ---
        $memo = app(UpsertMemoAction::class)($meeting, '次回までに過去問を 1 年分');

        // --- Assert ---
        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => '次回までに過去問を 1 年分',
        ]);
        $this->assertSame($meeting->id, $memo->meeting_id);
    }

    public function test_updates_the_existing_memo_without_adding_a_row(): void
    {
        // --- Arrange: 既にメモがある面談。upsert の「更新側」を通す ---
        $meeting = $this->meeting('completed');
        MeetingMemo::factory()->forMeeting($meeting)->create(['body' => '最初のメモ']);

        // --- Act ---
        app(UpsertMemoAction::class)($meeting, '書き直したメモ');

        // --- Assert ---
        // 1 面談 : 1 メモ(meeting_memos.meeting_id は UNIQUE)。行が増えていないことまで見る——
        // updateOrCreate が create に化けると、ここで 2 件になって気づける
        $this->assertSame(1, MeetingMemo::query()->where('meeting_id', $meeting->id)->count());
        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => '書き直したメモ',
        ]);
    }

    public function test_allows_a_memo_on_a_completed_meeting(): void
    {
        // --- Arrange ---
        // 面談が終わった後に書くのが本来の使い方なので、completed は許可されていなければならない
        $meeting = $this->meeting('completed');

        // --- Act ---
        app(UpsertMemoAction::class)($meeting, '合格ラインまであと一歩');

        // --- Assert ---
        $this->assertDatabaseHas('meeting_memos', ['meeting_id' => $meeting->id]);
    }

    public function test_rejects_a_memo_on_a_canceled_meeting(): void
    {
        // --- Arrange: 実施されなかった面談。メモを残す対象ではない ---
        $meeting = $this->meeting('canceled');

        // --- Assert(先に宣言する): ガードが働いて例外になること ---
        $this->expectException(MeetingStatusTransitionException::class);

        // --- Act ---
        app(UpsertMemoAction::class)($meeting, '書けないはずのメモ');
    }

    public function test_writes_nothing_when_the_guard_rejects(): void
    {
        // --- Arrange ---
        $meeting = $this->meeting('canceled');

        // --- Act: 例外は握りつぶして、DB に副作用が残っていないかだけを見る ---
        try {
            app(UpsertMemoAction::class)($meeting, '書けないはずのメモ');
        } catch (MeetingStatusTransitionException) {
            // 例外が出ること自体は上のテストで検証済み
        }

        // --- Assert: ガードで弾かれた場合はメモが 1 件も作られない ---
        $this->assertDatabaseMissing('meeting_memos', ['meeting_id' => $meeting->id]);
    }
}
