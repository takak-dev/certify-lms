<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Models\Announcement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 配信履歴の一覧を取得するユースケース。手本: `Certification\IndexAction`。
 *
 * 並びは配信時刻の降順(新しい順)。原典「配信履歴の一覧を時系列で閲覧できる」に対し、
 * 直近の配信を最初に見せるほうが監査の用途に合う。
 *
 * ⭐ with() で 3 つの関連を先読みするのは N+1 を避けるため。
 * 一覧の各行が targetCertification / targetUser / createdBy を参照しており
 * (announcement/management/index.blade.php:55,57,63)、先読みしないと 1 ページ 20 行で 1 + 60 本のクエリになる
 * (同じ問題を T-B-01 で直した)。
 */
final class IndexAction
{
    public function __invoke(int $perPage = 20): LengthAwarePaginator
    {
        return Announcement::query()
            ->with(['targetCertification', 'targetUser', 'createdBy'])
            ->orderByDesc('dispatched_at')
            // 同時刻の配信が並んだときに順序がぶれないよう、主キーで決着をつける
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
