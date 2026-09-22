<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 受講生本人の面談一覧画面に渡すデータを準備する Action。
 *
 * 一覧は常に scheduled_at の降順。filter で対象期間だけを切り替える
 * (upcoming = これから / past = 済んだ分 / all = 全部)。未知の値は upcoming として扱う——
 * IndexRequest の rules() が 'in:upcoming,past,all' で弾くため、ここへ来るのは 3 種か null のみ。
 *
 * 残面談回数(meetingsRemaining)も同じ画面が使うため、この Action がまとめて返す。
 * 残数は列ではなく meeting_quota_transactions の積み上げで求まるので、
 * 取得は MeetingQuotaService に委ねる(MeetingQuotaService.php:27)。
 *
 * @return array{
 *     meetings: LengthAwarePaginator<Meeting>,
 *     meetingsRemaining: int,
 * }
 */
final class IndexAction
{
    public function __construct(private readonly MeetingQuotaService $meetingQuota) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $student, string $filter): array
    {
        $query = Meeting::query()
            ->with(['enrollment.certification', 'coach'])
            ->forStudent($student)
            ->orderByDesc('scheduled_at');

        $meetings = match ($filter) {
            'past' => $query->past()->paginate(20),
            'all' => $query->paginate(20),
            default => $query->upcoming()->paginate(20),
        };

        return [
            'meetings' => $meetings,
            'meetingsRemaining' => $this->meetingQuota->remaining($student),
        ];
    }
}
