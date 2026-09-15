<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Meeting;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * 担当コーチ集合の面談可能時間枠を 60 分単位で展開し、空きスロットを集計する Service。
 *
 * 受講生の予約画面が「該当資格の担当コーチ全員の有効枠 Union」を 1 日単位で取得し、
 * 同コーチ・同時刻に存在する面談(status を問わない)を除外して各スロットの「予約可能なコーチ数」を返す。受講生にコーチ個別は提示せず、
 * 予約確定時にコーチを自動割当する。
 */
final class MeetingAvailabilityService
{
    /**
     * 指定 Certification の担当コーチ集合について、指定日 1 日分の 60 分単位空きスロットを返す。
     *
     * 1 リクエストあたり availability 1 クエリ + meetings 1 クエリ で完結させる。
     *
     * @return Collection<int, array{slot_start: Carbon, slot_end: Carbon, available_coach_count: int}>
     */
    public function slotsForCertification(Certification $certification, Carbon $date): Collection
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();

        $coaches = $certification->coaches()->get();
        if ($coaches->isEmpty()) {
            return collect();
        }

        $coachIds = $coaches->pluck('id')->all();

        $availabilities = $this->activeAvailabilities($certification, $date);

        // ⚠️ **status で絞らない**(B-A-01)。(coach_id, scheduled_at) UNIQUE は状態を問わないので、
        // canceled が残る枠は物理的に埋まっている——それを「空き」と表示するのはデータとして誤りで、
        // 押すと 409 になる食い違いが出る(decisions #167(1))。
        // この条件を戻すと test_canceled_meetings_also_occupy_the_slot が落ちる。
        $existingMeetings = Meeting::query()
            ->whereIn('coach_id', $coachIds)
            ->whereBetween('scheduled_at', [$dayStart, $dayEnd])
            ->get(['coach_id', 'scheduled_at']);

        // 予約済スロットを (coach_id => Set<H:i>) で索引化
        $bookedByCoach = $existingMeetings
            ->groupBy('coach_id')
            ->map(fn ($rows) => $rows->map(fn (Meeting $m) => $m->scheduled_at->format('H:i'))->all());

        /** @var array<string, int> $slotCounts スロット開始時刻(H:i) → available coach 数 */
        $slotCounts = [];

        foreach ($availabilities as $availability) {
            foreach ($this->slotStarts($availability, $date) as $slot) {
                $slotKey = $slot->format('H:i');
                $coachId = $availability->coach_id;
                $booked = $bookedByCoach[$coachId] ?? [];

                if (! in_array($slotKey, $booked, true)) {
                    $slotCounts[$slotKey] = ($slotCounts[$slotKey] ?? 0) + 1;
                }
            }
        }

        ksort($slotCounts);

        return collect($slotCounts)->map(function (int $count, string $time) use ($date) {
            $start = Carbon::parse($date->format('Y-m-d').' '.$time);

            return [
                'slot_start' => $start,
                'slot_end' => $start->copy()->addHour(),
                'available_coach_count' => $count,
            ];
        })->values();
    }

    /**
     * 担当コーチの稼働時間から 60 分スロットの開始時刻を列挙する(占有状況は見ない)。
     *
     * slotsForCertification() が満枠のスロットを配列から落とすため、
     * 「枠として存在するか」だけを知りたい validateSlot() はこちらを使う。
     *
     * @return Collection<int, Carbon>
     */
    private function gridSlots(Certification $certification, Carbon $date): Collection
    {
        $availabilities = $this->activeAvailabilities($certification, $date);

        $starts = [];

        foreach ($availabilities as $availability) {
            foreach ($this->slotStarts($availability, $date) as $slot) {
                // コーチが違っても同じ時刻は 1 つの枠として扱う(H:i をキーに重複排除)
                $starts[$slot->format('H:i')] = $slot;
            }
        }

        return collect($starts)->values();
    }

    /**
     * 1 つの稼働枠を 60 分スロットの開始時刻に刻む。**格子の定義はここ 1 箇所だけ**。
     *
     * ⚠️ 終了がはみ出すスロットは作らない(稼働 9:00-17:30 なら 16:00 開始が最後)。
     * この条件を表示側と予約側で別々に書いていたことが、B-A-01 のレビューで見つかった
     * 2 つの穴(画面に出ない時刻が POST で通る)の原因だった。片方だけ壊れることが
     * 構造的に起きないよう、両方の呼出元がこのメソッドを通る。
     *
     * @return array<int, Carbon>
     */
    private function slotStarts(CoachAvailability $availability, Carbon $date): array
    {
        $starts = [];
        $slot = Carbon::parse($date->format('Y-m-d').' '.$availability->start_time);
        $end = Carbon::parse($date->format('Y-m-d').' '.$availability->end_time);

        while ($slot->copy()->addHour() <= $end) {
            $starts[] = $slot->copy();
            $slot->addHour();
        }

        return $starts;
    }

    /**
     * その時刻に 60 分スロットを提供できるコーチの id を返す(B-A-01)。
     *
     * ⚠️ 予約時のコーチ候補は**必ずここを通す**。SQL の範囲判定(`start_time <= 時刻 < end_time`)で
     * 代用すると、稼働の開始が毎時 00 分でないコーチが「格子に乗らないのに候補に残る」——
     * 例: 稼働 09:30-18:00 のコーチは 10:00 の枠を提供しない(提供するのは 09:30, 10:30, …)が、
     * 範囲判定では候補に入る。結果、**画面に出ない時刻が POST で通る**(CLAUDE.md §3-7。実測で確認)。
     * 格子の定義は slotStarts() 1 箇所だけにする、という原則をコーチ単位でも守るためのメソッド。
     *
     * @return array<int, string> coach_id の配列
     */
    public function coachIdsOfferingSlot(Certification $certification, Carbon $scheduledAt): array
    {
        $day = $scheduledAt->copy()->startOfDay();
        $coachIds = [];

        foreach ($this->activeAvailabilities($certification, $day) as $availability) {
            foreach ($this->slotStarts($availability, $day) as $slot) {
                if ($slot->equalTo($scheduledAt)) {
                    $coachIds[$availability->coach_id] = true;

                    break;
                }
            }
        }

        return array_keys($coachIds);
    }

    /**
     * 担当コーチ集合の、その曜日の有効な稼働枠を取る。
     *
     * @return Collection<int, CoachAvailability>
     */
    private function activeAvailabilities(Certification $certification, Carbon $date): Collection
    {
        return CoachAvailability::query()
            ->whereIn('coach_id', $certification->coaches()->pluck('users.id'))
            ->where('day_of_week', $date->dayOfWeek)
            ->where('is_active', true)
            ->get();
    }

    /**
     * 指定 scheduled_at が certification 担当コーチ集合の有効枠内かを検証する。
     * 枠外なら MeetingOutOfAvailabilityException を throw する。
     *
     * ⚠️ **空いているかどうかは見ない**(B-A-01)。ここで満枠を弾くと「予約できない時刻」と
     * 「予約できる時刻だが満枠」が同じ 422 になり、原典が満枠に要求する 409 を返せなくなる。
     * 満枠の判定は「担当できるコーチを順に INSERT して全員 UNIQUE で弾かれたか」に委ねる
     * —— 並行予約では「今空いているか」は次の瞬間に変わるため、DB を最終的な空席判定器として使う。
     *
     * @throws MeetingOutOfAvailabilityException
     */
    public function validateSlot(Certification $certification, Carbon $scheduledAt): void
    {
        // ⚠️ 満枠は見ないが、格子(60 分スロット)には乗っていることを要求する。
        // 稼働時間の範囲だけで判定すると、画面に出ない時刻(例: 稼働 9:00-17:30 の 17:00)が
        // POST で通り、稼働をはみ出す予約ができてしまう(CLAUDE.md §3-7)。
        $matched = $this->gridSlots($certification, $scheduledAt->copy()->startOfDay())
            ->contains(fn (Carbon $slotStart) => $slotStart->equalTo($scheduledAt));

        if (! $matched) {
            throw new MeetingOutOfAvailabilityException;
        }
    }
}
