<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeetingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function attachCoach(Certification $certification, User $coach, User $admin): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }

    public function test_student_index_lists_only_own_meetings(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $own = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        $other = Meeting::factory()->reserved()->forCoach($coach)->forStudent($otherStudent)->create([
            'scheduled_at' => now()->addDays(4)->startOfHour(),
        ]);

        $response = $this->actingAs($student)->get(route('meetings.index'));

        $response->assertOk();
        $response->assertViewIs('meeting.index');
        $response->assertViewHas('meetings', fn ($meetings) => $meetings->contains('id', $own->id)
            && ! $meetings->contains('id', $other->id));
    }

    public function test_show_blocks_third_party(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thirdParty = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create();

        $response = $this->actingAs($thirdParty)->get(route('meetings.show', $meeting));

        $response->assertForbidden();
    }

    public function test_show_allows_owner(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create();

        $this->actingAs($student)->get(route('meetings.show', $meeting))->assertOk();
        $this->actingAs($coach)->get(route('meetings.show', $meeting))->assertOk();
    }

    public function test_create_requires_enrollment_ownership(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        $foreignEnrollment = Enrollment::factory()->for($other, 'user')->for($certification)->learning()->create();

        $response = $this->actingAs($student)->get(route('meetings.create', $foreignEnrollment));

        $response->assertForbidden();
    }

    public function test_store_creates_meeting_for_owner(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();

        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0); // 次の月曜 10:00(未来)

        $response = $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('meetings', [
            'student_id' => $student->id,
            'coach_id' => $coach->id,
            'enrollment_id' => $enrollment->id,
            'status' => MeetingStatus::Reserved->value,
        ]);
    }

    public function test_store_rejects_non_zero_minutes(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();

        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 30); // 次の月曜 10:30(分が 0 でない=不正)
        $response = $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        $response->assertSessionHasErrors('scheduled_at');
        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_cancel_blocks_third_party(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thirdParty = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        $response = $this->actingAs($thirdParty)->post(route('meetings.cancel', $meeting));

        $response->assertForbidden();
        $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
    }

    public function test_cancel_allows_owner(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        $response = $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        $response->assertRedirect();
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
    }

    public function test_index_as_coach_only_lists_own_meetings(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $own = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        $other = Meeting::factory()->reserved()->forCoach($otherCoach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(4)->startOfHour(),
        ]);

        $response = $this->actingAs($coach)->get(route('coach.meetings.index'));

        $response->assertOk();
        $response->assertViewIs('meeting.coach.index');
        $response->assertViewHas('meetings', fn ($meetings) => $meetings->contains('id', $own->id)
            && ! $meetings->contains('id', $other->id));
    }

    public function test_upsert_memo_only_for_assigned_coach(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create();

        $response = $this->actingAs($otherCoach)->put(route('coach.meetings.memo', $meeting), [
            'body' => '他人のメモを書く試み',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('meeting_memos', ['meeting_id' => $meeting->id]);
    }

    public function test_upsert_memo_succeeds_for_assigned_coach(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create();

        $response = $this->actingAs($coach)->put(route('coach.meetings.memo', $meeting), [
            'body' => '初回面談メモ',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => '初回面談メモ',
        ]);
    }

    public function test_fetch_availability_returns_json_slots(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();

        $date = now()->startOfDay()->next(Carbon::MONDAY)->format('Y-m-d'); // 次の月曜(未来)
        $response = $this->actingAs($student)->getJson(
            route('meetings.availability', $enrollment)."?date={$date}"
        );

        $response->assertOk();
        $response->assertJsonStructure([
            'date',
            'slots' => [
                '*' => ['slot_start', 'slot_end', 'available_coach_count'],
            ],
        ]);
        $this->assertCount(3, $response->json('slots'));
    }

    public function test_graduated_student_cannot_access_create(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();

        $response = $this->actingAs($student)->get(route('meetings.create', $enrollment));

        $response->assertForbidden();
    }

    /**
     * 先頭候補が直前に取られていても、次の候補コーチで予約が成立することを検証する(B-A-01)。
     *
     * 並行予約では全員が同じ候補集合を読み、sortByLoad が安定ソートで同じ順序を返すため、
     * 先頭のコーチは必ず UNIQUE で衝突する。そこで諦めず次の候補へ進むのが本チケットの中核。
     * 逐次では衝突を作れないので、候補抽出をすり抜ける canceled で先頭コーチを塞いで再現する。
     */
    public function test_store_falls_back_to_next_coach_when_first_candidate_is_taken(): void
    {
        // Arrange: 同条件のコーチ 2 名。ULID 昇順で先頭になる側を canceled で塞ぐ。
        //          canceled は候補抽出をすり抜けるので候補に残り、INSERT で UNIQUE に弾かれる。
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $otherStudent = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();

        $coaches = collect();
        for ($i = 0; $i < 2; $i++) {
            $coach = User::factory()->coach()->inProgress()->create([
                'meeting_url' => 'https://meet.example.com/coach-room-'.$i,
            ]);
            $this->attachCoach($certification, $coach, $admin);
            CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
            $coaches->push($coach);
        }

        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0); // 次の月曜 10:00(未来)

        $takenCoach = $coaches->sortBy('id')->first();
        $freeCoach = $coaches->sortBy('id')->last();
        Meeting::factory()->canceled()->forCoach($takenCoach)->forStudent($otherStudent)->create([
            'scheduled_at' => $scheduledAt,
        ]);

        // Act
        $response = $this->actingAs($student)
            ->from(route('meetings.create', $enrollment))
            ->post(route('meetings.store', $enrollment), [
                'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
                'topic' => '相談したい',
            ]);

        // Assert: 409 にならず、2 番手のコーチで成立する。
        $response->assertSessionHas('success', '面談を予約しました。');
        $this->assertDatabaseHas('meetings', [
            'student_id' => $student->id,
            'coach_id' => $freeCoach->id,
            'status' => MeetingStatus::Reserved->value,
        ]);
    }

    /**
     * 同コーチ・同時刻の 2 件目を DB が拒否することを検証する(B-A-01)。
     *
     * アプリ層を通さず制約だけを見る。store() 経由のテストも UNIQUE を落とせば落ちる(変異で確認済み)が、
     * それらは「409 が返ること」を見ているだけで、**制約そのものが status を問わないこと**は検証していない。
     * ここは canceled を 2 件目に置いて、状態に依らず 1 件しか入らないことを直接確かめる(decisions #143)。
     */
    public function test_database_rejects_duplicate_coach_and_slot(): void
    {
        // Arrange: 同じコーチ・同じ時刻。status は問わない(canceled でも 2 件目は入らない)。
        $coach = User::factory()->coach()->create();
        $scheduledAt = now()->addDay()->setTime(10, 0);
        Meeting::factory()->reserved()->forCoach($coach)->create(['scheduled_at' => $scheduledAt]);

        // Act & Assert
        $this->expectException(UniqueConstraintViolationException::class);
        Meeting::factory()->canceled()->forCoach($coach)->create(['scheduled_at' => $scheduledAt]);
    }

    /**
     * 満枠の枠を予約したとき、422(面談可能時間外)ではなく 409(空きコーチなし)で拒否されることを検証する(B-A-01)。
     *
     * 原典は「2 件目は 409(空きコーチなしエラー)で拒否」と明記している。
     * validateSlot() が「予約できない時刻」と「予約できる時刻だが満枠」を同じ 422 にしていると満たせない。
     * Handler は 409 も 422 も同じ error フラッシュに畳むため、JSON で投げて素の HTTP ステータスを見る。
     */
    public function test_store_rejects_full_slot_with_409_not_422(): void
    {
        // Arrange: コーチ 1 名。その唯一の枠を予約済みで塞ぐ。
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $otherStudent = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0); // 次の月曜 10:00(未来)

        Meeting::factory()->reserved()->forCoach($coach)->forStudent($otherStudent)->create([
            'scheduled_at' => $scheduledAt,
        ]);

        // Act
        $response = $this->actingAs($student)->postJson(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        // Assert
        $response->assertStatus(409);
        $this->assertDatabaseCount('meetings', 1);
    }

    /**
     * コーチの稼働時間外は 422 のままであることを検証する(B-A-01)。
     *
     * 満枠を 409 に変えた結果、「そもそも予約できない時刻」まで 409 になっていないかを押さえる。
     */
    public function test_store_rejects_out_of_availability_with_422(): void
    {
        // Arrange: 稼働は 9:00-18:00。その外側(20:00)を指定する。
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(20, 0); // 稼働時間外

        // Act
        $response = $this->actingAs($student)->postJson(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        // Assert
        $response->assertStatus(422);
        $this->assertDatabaseCount('meetings', 0);
    }

    /**
     * 画面に出ないスロットを POST で予約できないことを検証する(B-A-01)。
     *
     * validateSlot() が稼働時間の範囲だけを見ると、60 分スロットの格子に乗らない時刻が通ってしまう。
     * 稼働 9:00-17:30 の場合、画面に出るのは 16:00 まで。17:00 を通すと稼働を 30 分はみ出す。
     */
    public function test_store_rejects_slot_that_overflows_availability(): void
    {
        // Arrange: 稼働が 30 分単位で終わるコーチ(9:00-17:30)。
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '17:30:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(17, 0); // 17:00-18:00 は 17:30 を超える

        // Act
        $response = $this->actingAs($student)->postJson(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        // Assert
        $response->assertStatus(422);
        $this->assertDatabaseCount('meetings', 0);
    }

    /**
     * 稼働の終わりが早いコーチが、はみ出す枠の候補に残らないことを検証する(B-A-01)。
     *
     * 格子(gridSlots)は担当コーチ全員の稼働の和集合なので、9:00-18:00 のコーチがいれば 17:00 も格子に乗る。
     * そのとき候補抽出が「17:00 に稼働しているか」を時点で判定すると、9:00-17:30 のコーチまで候補に残り、
     * 稼働を 30 分はみ出す予約が入る。**画面に 17:00 は出ていない**(A は予約済、B は格子に乗らない)ので、
     * これは「画面から消えた枠が POST で通る」穴になる(CLAUDE.md §3-7)。
     *
     * ⚠️ 支給時点は validateSlot() が表示ロジックを共有していたため塞がっていた。満枠を 409 にするために
     * 切り離した結果、候補抽出側にこの穴が残った——本 PR で開けた穴を本 PR で塞いでいる(実測で確認)。
     */
    public function test_store_does_not_assign_coach_whose_availability_ends_mid_slot(): void
    {
        // Arrange: A は 18:00 まで、B は 17:30 まで。A の 17:00 を先に埋めておく。
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $otherStudent = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $coachA = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-a',
        ]);
        $coachB = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-b',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coachA, $admin);
        $this->attachCoach($certification, $coachB, $admin);
        CoachAvailability::factory()->forCoach($coachA)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        CoachAvailability::factory()->forCoach($coachB)->onDay(1)->timeRange('09:00:00', '17:30:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(17, 0);

        Meeting::factory()->reserved()->forCoach($coachA)->forStudent($otherStudent)->create([
            'scheduled_at' => $scheduledAt,
        ]);

        // Act
        $response = $this->actingAs($student)->postJson(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        // Assert: B は候補に残らないので空きコーチなし(409)。B に予約は入らない。
        $response->assertStatus(409);
        $this->assertDatabaseMissing('meetings', [
            'coach_id' => $coachB->id,
            'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 稼働の開始が毎時 00 分でないコーチが、その格子に無い時刻の予約を受けないことを検証する(B-A-01)。
     *
     * 前のテストが稼働の「終わり」側なら、こちらは「始まり」側。どちらも同じ原因で起きる——
     * 格子(各コーチの start_time から 60 分刻み)と候補抽出の基準がずれること。
     *
     * A(09:00-18:00) の 10:00 が埋まっていると、画面から 10:00 は消える
     * (B の格子は 09:30, 10:30, … なので 10:00 を作らない)。それでも POST が通ってしまうと、
     * 「画面に出ない枠が URL で予約できる」ことになる(CLAUDE.md §3-7)。
     *
     * ⚠️ 支給時点は validateSlot() が表示ロジックを共有していたため 422 で塞がっていた(実測)。
     */
    public function test_store_does_not_assign_coach_whose_availability_starts_off_grid(): void
    {
        // Arrange: A は 09:00 始まり、B は 09:30 始まり。A の 10:00 を埋める。
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $otherStudent = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $coachA = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-a',
        ]);
        $coachB = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-b',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coachA, $admin);
        $this->attachCoach($certification, $coachB, $admin);
        CoachAvailability::factory()->forCoach($coachA)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        CoachAvailability::factory()->forCoach($coachB)->onDay(1)->timeRange('09:30:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        Meeting::factory()->reserved()->forCoach($coachA)->forStudent($otherStudent)->create([
            'scheduled_at' => $scheduledAt,
        ]);

        // Act
        $response = $this->actingAs($student)->postJson(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        // Assert: B は 10:00 を提供しないので空きコーチなし(409)。
        $response->assertStatus(409);
        $this->assertDatabaseMissing('meetings', [
            'coach_id' => $coachB->id,
            'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_store_blocks_double_booking_for_same_coach_and_slot(): void
    {
        // Arrange: 予約可能コンテキスト + 同コーチ・同時刻に canceled 面談を 1 件先在させる。
        // ⚠️ canceled は候補抽出(reserved / completed のみ除外)を**すり抜けて候補に残る**。
        // コーチは 1 名なので、INSERT が (coach_id, scheduled_at) UNIQUE で弾かれ、次の候補が無く 409 になる。
        // (「候補ゼロで 409」ではない——候補は 1 名いる。B-A-01)
        // (支給時点の想定は「すり抜けて UNIQUE で弾かれる」だった)。UNIQUE 自体は
        // test_database_rejects_duplicate_coach_and_slot が別途固定する。
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $otherStudent = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0); // 次の月曜 10:00(未来)

        Meeting::factory()->canceled()->forCoach($coach)->forStudent($otherStudent)->create([
            'scheduled_at' => $scheduledAt,
        ]);

        // Act
        $response = $this->actingAs($student)
            ->from(route('meetings.create', $enrollment))
            ->post(route('meetings.store', $enrollment), [
                'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
                'topic' => '相談したい',
            ]);

        // Assert: 二重予約は成立せず、新規 reserved は作られない(canceled の 1 件のみが残る)
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(
            0,
            Meeting::query()->where('status', MeetingStatus::Reserved->value)->count(),
            '同コーチ・同時刻の二重予約は成立しないはず(唯一の候補が UNIQUE で弾かれ、次候補が無いので 409)',
        );
    }

    public function test_cancel_refunds_meeting_quota(): void
    {
        // Arrange: 予約済(残数消費済)面談 1 件。キャンセルで返却記録が作られることを確認する。
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        // Act
        $response = $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        // Assert: キャンセル成立 + 消費分 1 回が返却記録として作られる
        $response->assertRedirect();
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
            'amount' => 1,
        ]);
    }

    /**
     * B-B-10 の回帰防止テスト。
     *
     * 返却先は「キャンセルした人」ではなく「面談の受講生」でなければならない。
     * `test_cancel_refunds_meeting_quota`(同ファイル) は受講生が自分でキャンセルするため、
     * 操作者と受講生が同一人物になり、返却先を操作者に取り違えても緑のまま通ってしまう。
     * コーチがキャンセルする経路を通して初めて両者の差が現れる。
     * (チケット原典「受講生(student) / コーチ(coach)が予約済みの面談をキャンセルすると」/
     *  MeetingPolicy::cancel が受講生とコーチの双方に許可している)
     */
    public function test_cancel_by_coach_refunds_quota_to_the_student(): void
    {
        // Arrange: 予約済(開始前)の面談 1 件。max_meetings は残数計算の起点として明示しておく。
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        // Arrange: 要件「キャンセル後の残数がキャンセル前 + 1」を検証するため、事前の残数を控える。
        // 残数はカラムではなく max_meetings + SUM(amount) の計算結果なので、都度 Service に問い合わせる。
        $quotaService = app(MeetingQuotaService::class);
        $remainingBefore = $quotaService->remaining($student);

        // Act: 受講生ではなく担当コーチとしてキャンセルする
        $response = $this->actingAs($coach)->post(route('meetings.cancel', $meeting));

        // Assert: キャンセル自体は成立する
        $response->assertRedirect();
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);

        // Assert: 返却行の user_id は受講生である
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
            'amount' => 1,
        ]);

        // Assert: コーチ宛の取引行は 1 件も作られない(コーチに面談回数が付いてはいけない)
        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'user_id' => $coach->id,
        ]);

        // Assert: 残数そのものが +1 になる。行の存在だけを見ると、
        // MeetingQuotaService::remaining() の集計対象から Refunded が外れても検出できない。
        $this->assertSame($remainingBefore + 1, $quotaService->remaining($student));
    }
}
