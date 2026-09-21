<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
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

    // ================================================================
    // S-A-01 Google カレンダー連携による空き枠の除外
    // ================================================================

    /**
     * Google の busy 時間帯を固定値で返す差し替え Service を Container に登録する。
     *
     * ⚠️ 本物の GoogleCalendarService を呼ぶと実際に Google へ通信してしまい、
     *    テストがネットワークとトークンの状態に依存する。ここで検証したいのは
     *    「busy が返ってきたとき MeetingAvailabilityService がどう振る舞うか」だけなので、
     *    通信そのものは差し替える(本格的なモックは T-A-04 の担当)。
     *
     * @param array<int, array{0: string, 1: string}> $periods ['開始', '終了'] の組
     */
    private function fakeGoogleBusy(array $periods): void
    {
        $this->mock(GoogleCalendarService::class, function ($mock) use ($periods) {
            $mock->shouldReceive('busyPeriods')->andReturn(array_map(
                fn (array $p): array => [
                    'start' => Carbon::parse($p[0]),
                    'end' => Carbon::parse($p[1]),
                ],
                $periods,
            ));
        });
    }

    /**
     * 連携済コーチの Google に予定がある時刻は、受講生の予約画面の空き枠から外れる。
     *
     * 原典「連携済コーチが Google カレンダーで予定を持つ時刻は、受講生の予約画面の空き枠から外れる」。
     *
     * データ: 稼働 09:00-12:00(= 09 / 10 / 11 の 3 枠)に、Google 側で 10:00-11:00 の予定を 1 件置く。
     */
    public function test_slots_exclude_times_busy_on_google_calendar(): void
    {
        // Arrange
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        GoogleCredential::factory()->forUser($coach)->create();
        $this->fakeGoogleBusy([['2026-06-01 10:00:00', '2026-06-01 11:00:00']]);

        // Act
        $slots = app(MeetingAvailabilityService::class)
            ->slotsForCertification($certification, Carbon::parse('2026-06-01'));

        // Assert: 10:00 だけが消える
        $this->assertSame(
            ['09:00', '11:00'],
            $slots->pluck('slot_start')->map(fn (Carbon $s) => $s->format('H:i'))->all(),
        );
    }

    /**
     * 未連携のコーチは Google を参照せず、従来どおりの判定で動く。
     *
     * 原典「連携していないコーチは従来通りの空き判定で動く」/ decisions #146。
     *
     * ⚠️ この 1 本が「既存機能を壊していない」ことの担保。busyPeriods が busy を返す状況でも、
     *    google_credentials に行が無いコーチの枠は 1 つも消えてはいけない。
     */
    public function test_slots_are_untouched_for_coach_without_google_credential(): void
    {
        // Arrange: 連携していないコーチ。Google 側は「常に busy」を返す設定にしておく。
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        $this->fakeGoogleBusy([['2026-06-01 09:00:00', '2026-06-01 12:00:00']]);

        // Act
        $slots = app(MeetingAvailabilityService::class)
            ->slotsForCertification($certification, Carbon::parse('2026-06-01'));

        // Assert: 3 枠とも残る(Google を一度も見ていない)
        $this->assertSame(
            ['09:00', '10:00', '11:00'],
            $slots->pluck('slot_start')->map(fn (Carbon $s) => $s->format('H:i'))->all(),
        );
    }

    /**
     * Google との通信に失敗しても空き枠の表示は止まらない。
     *
     * 原典 共通の振る舞い「Google との通信に失敗しても、空き枠の表示・面談の予約・
     * 面談のキャンセルといった面談機能の根幹は止まらない」。
     *
     * ⚠️ 失敗時は「予定なし」に倒す。安全側(全部消す)ではないが、原典が「止まらない」を
     *    優先しているため。逆に倒すと Google の一時的な不調で予約が一切できなくなる。
     */
    public function test_slots_survive_when_google_request_fails(): void
    {
        // Arrange: 連携済だが busyPeriods が必ず例外を投げる状態。
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        GoogleCredential::factory()->forUser($coach)->create();

        $this->mock(GoogleCalendarService::class, function ($mock) {
            $mock->shouldReceive('busyPeriods')->andThrow(new RuntimeException('Google is down'));
        });

        // Act: 例外が外へ漏れず、通常どおり枠が返ること
        $slots = app(MeetingAvailabilityService::class)
            ->slotsForCertification($certification, Carbon::parse('2026-06-01'));

        // Assert
        $this->assertSame(
            ['09:00', '10:00', '11:00'],
            $slots->pluck('slot_start')->map(fn (Carbon $s) => $s->format('H:i'))->all(),
        );
    }

    /**
     * ⭐ 画面から消えた時刻は、予約の経路でも通らない。
     *
     * coachIdsOfferingSlot() は予約確定時のコーチ候補を決めるメソッド(B-A-01)。
     * ここに Google の判定を入れ忘れると「画面には出ないが POST では通る」状態になり、
     * ダブルブッキングを構造的に消すという原典の目的が達成されない(CLAUDE.md §3-7)。
     *
     * ⚠️ この 1 本が無いと、slotsForCertification() 側の除外だけ書いても全テストが緑になる。
     */
    public function test_busy_coach_is_not_a_candidate_when_booking(): void
    {
        // Arrange: 10:00 の枠を提供するコーチ 1 人。Google 側に 10:00-11:00 の予定あり。
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        GoogleCredential::factory()->forUser($coach)->create();
        $this->fakeGoogleBusy([['2026-06-01 10:00:00', '2026-06-01 11:00:00']]);

        $service = app(MeetingAvailabilityService::class);

        // Act & Assert: 10:00 は候補ゼロ、隣の 09:00 は候補に残る
        $this->assertSame([], $service->coachIdsOfferingSlot($certification, Carbon::parse('2026-06-01 10:00:00')));
        $this->assertSame([$coach->id], $service->coachIdsOfferingSlot($certification, Carbon::parse('2026-06-01 09:00:00')));
    }

    /**
     * 予定とスロットが端で接するだけなら空き枠は消えない。
     *
     * 09:00-10:00 の予定と 10:00-11:00 のスロットは重なっていない。
     * ここを「重なり」と判定すると、面談の直後・直前の枠が不当に消えて枠が痩せる。
     *
     * ⚠️ 境界の不等号(< と <=)を取り違えたときに落ちる 1 本。
     */
    public function test_adjacent_google_event_does_not_remove_the_slot(): void
    {
        // Arrange: Google 側の予定は 09:00-10:00 ちょうど。
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('10:00:00', '12:00:00')->create();
        GoogleCredential::factory()->forUser($coach)->create();
        $this->fakeGoogleBusy([['2026-06-01 09:00:00', '2026-06-01 10:00:00']]);

        // Act
        $slots = app(MeetingAvailabilityService::class)
            ->slotsForCertification($certification, Carbon::parse('2026-06-01'));

        // Assert: 10:00 / 11:00 の 2 枠がそのまま残る
        $this->assertSame(
            ['10:00', '11:00'],
            $slots->pluck('slot_start')->map(fn (Carbon $s) => $s->format('H:i'))->all(),
        );
    }

    /**
     * ⭐ 同じコーチ・同じ日への問い合わせは短時間キャッシュされ、Google を何度も呼ばない。
     *
     * これが無いと、受講生が予約画面の日付を往復するだけで「連携済コーチの人数」分の
     * Google 通信を無制限に起こせる。Google のクォータを使い切ると
     * googleBusyByCoach() のフォールバックで全コーチが「予定なし」扱いに倒れ、
     * S-A-01 のダブルブッキング防止そのものが無効化される。
     */
    public function test_busy_periods_are_cached_between_requests(): void
    {
        // Arrange
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        GoogleCredential::factory()->forUser($coach)->create();

        // busyPeriods は **1 回しか呼ばれてはいけない**
        $this->mock(GoogleCalendarService::class, function ($mock) {
            $mock->shouldReceive('busyPeriods')->once()->andReturn([
                ['start' => Carbon::parse('2026-06-01 10:00:00'), 'end' => Carbon::parse('2026-06-01 11:00:00')],
            ]);
        });

        $service = app(MeetingAvailabilityService::class);
        $date = Carbon::parse('2026-06-01');

        // Act: 同じ日を 3 回引く
        $first = $service->slotsForCertification($certification, $date);
        $service->slotsForCertification($certification, $date);
        $third = $service->slotsForCertification($certification, $date);

        // Assert: 結果は毎回同じで、10:00 が除外されている
        foreach ([$first, $third] as $slots) {
            $this->assertSame(
                ['09:00', '11:00'],
                $slots->pluck('slot_start')->map(fn (Carbon $s) => $s->format('H:i'))->all(),
            );
        }
    }

    /**
     * 失敗はキャッシュしない。
     *
     * ⚠️ 失敗を覚えてしまうと、Google が復旧しても TTL の間ずっと「予定なし」に倒れたままになる。
     *    1 回目は失敗、2 回目は成功、という並びで「2 回とも呼ばれる」ことを確かめる。
     */
    public function test_failed_lookups_are_not_cached(): void
    {
        // Arrange
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        GoogleCredential::factory()->forUser($coach)->create();

        // ⚠️ Mockery は andThrow()->andReturn() の連結では順番に返してくれない（実測）。
        //    呼び出し回数で分岐させる。
        $calls = 0;
        $this->mock(GoogleCalendarService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('busyPeriods')->twice()->andReturnUsing(function () use (&$calls): array {
                $calls++;

                if ($calls === 1) {
                    throw new RuntimeException('Google is down');
                }

                return [
                    ['start' => Carbon::parse('2026-06-01 10:00:00'), 'end' => Carbon::parse('2026-06-01 11:00:00')],
                ];
            });
        });

        $service = app(MeetingAvailabilityService::class);
        $date = Carbon::parse('2026-06-01');

        // Act
        $failed = $service->slotsForCertification($certification, $date);   // 失敗 → 予定なし扱い
        $recovered = $service->slotsForCertification($certification, $date); // 復旧 → 除外が効く

        // Assert
        $this->assertSame(
            ['09:00', '10:00', '11:00'],
            $failed->pluck('slot_start')->map(fn (Carbon $s) => $s->format('H:i'))->all(),
        );
        $this->assertSame(
            ['09:00', '11:00'],
            $recovered->pluck('slot_start')->map(fn (Carbon $s) => $s->format('H:i'))->all(),
        );
    }

    /**
     * ⭐ その日に稼働枠を持たないコーチには、Google へ問い合わせない。
     *
     * ⚠️ これが無いと「資格の全コーチを渡す」実装に戻しても全テストが緑のまま
     *    （モックが全員に空配列を返すため結果が変わらない）。
     *    枠を出していない曜日のコーチにも通信が飛ぶのは、結果が捨てられる純粋な無駄で、
     *    外部 API のクォータを削る。
     *
     * データ: 同じ資格に 2 人。月曜に枠を持つのは 1 人だけ。
     */
    public function test_google_is_only_asked_about_coaches_working_that_day(): void
    {
        // Arrange
        $certification = Certification::factory()->published()->create();
        $working = User::factory()->coach()->create();
        $offDuty = User::factory()->coach()->create();
        $this->attachCoach($certification, $working);
        $this->attachCoach($certification, $offDuty);

        // 月曜に枠を持つのは $working だけ（$offDuty は火曜のみ）
        CoachAvailability::factory()->forCoach($working)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        CoachAvailability::factory()->forCoach($offDuty)->onDay(2)->timeRange('09:00:00', '12:00:00')->create();

        // 2 人とも連携済みにしておく（絞り込みが無ければ 2 回呼ばれる）
        GoogleCredential::factory()->forUser($working)->create();
        GoogleCredential::factory()->forUser($offDuty)->create();

        $this->mock(GoogleCalendarService::class, function ($mock) use ($working) {
            // ⚠️ **1 回だけ**、しかも **$working について** 呼ばれること。
            //    回数だけ見ると「絞り込みの向きを逆にした（枠を持たない側を渡す）」変異を
            //    単体では殺せないので、誰について問い合わせたかまで見る。
            $mock->shouldReceive('busyPeriods')->once()
                ->withArgs(fn ($credential): bool => $credential->user_id === $working->id)
                ->andReturn([]);
        });

        // Act: 月曜
        $slots = app(MeetingAvailabilityService::class)
            ->slotsForCertification($certification, Carbon::parse('2026-06-01'));

        // Assert: 枠は $working の分だけ出る
        $this->assertSame(
            ['09:00', '10:00', '11:00'],
            $slots->pluck('slot_start')->map(fn (Carbon $s) => $s->format('H:i'))->all(),
        );
    }
}
