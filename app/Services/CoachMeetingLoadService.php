<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 候補コーチ集合を、過去 30 日の completed 件数が少ない順に並べる Service。
 *
 * 自動コーチ割当の負荷分散ロジックを担う。本体は全体の順序を返す `sortByLoad` で、
 * 予約確定処理(MeetingController::store)はこの順にコーチを試す——先頭が並行予約で取られても
 * 2 番手へ進めるようにするため(B-A-01)。`leastLoadedCoach` は先頭 1 名を取るだけの薄いラッパで、
 * 現在は本体からの呼出は無い(1 名だけ要る呼出元が現れたときのために残している)。
 *
 * 同数の場合は ULID 昇順で並べることで、どのリクエストからも決定論的に同じ順序を返す。
 */
final class CoachMeetingLoadService
{
    /**
     * 候補集合の中から、過去 30 日の completed 数が最少のコーチを 1 名返す。
     *
     * ⚠️ `sortByLoad()` の先頭を取るだけ。**予約確定処理はこちらを使わない**(B-A-01 以降)——
     * 並行予約では先頭が取られることがあり、1 名に絞ると 2 番手へ進めないため。
     *
     * @param Collection<int, User> $candidates 空き枠 ∩ 当該時刻に予約なし のコーチ集合
     *
     * @return User|null 候補が空なら null
     */
    public function leastLoadedCoach(Collection $candidates): ?User
    {
        return $this->sortByLoad($candidates)->first();
    }

    /**
     * 候補集合を「割り当てたい順」に並べて返す。第 1 キーは過去 30 日の completed 数、第 2 キーは ULID 昇順。
     *
     * B-A-01 で追加。並行予約では先頭のコーチが他リクエストに取られることがあるため、
     * 呼出側が 2 番手・3 番手へ順に進めるように、1 名ではなく全体の順序を渡す。
     *
     * @param Collection<int, User> $candidates
     *
     * @return Collection<int, User>
     */
    public function sortByLoad(Collection $candidates): Collection
    {
        $coachIds = $candidates->pluck('id')->all();

        /** @var array<string, int> $counts coach_id => completed_count */
        $counts = Meeting::query()
            ->whereIn('coach_id', $coachIds)
            ->where('status', MeetingStatus::Completed->value)
            ->where('scheduled_at', '>', now()->subDays(30))
            ->select('coach_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('coach_id')
            ->pluck('cnt', 'coach_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        // 第 1 キー: 過去 30 日 completed 数 / 第 2 キー: ULID 昇順 で安定ソートする
        return $candidates->sortBy([
            fn (User $a, User $b) => ($counts[$a->id] ?? 0) <=> ($counts[$b->id] ?? 0),
            fn (User $a, User $b) => strcmp($a->id, $b->id),
        ])->values();
    }
}
