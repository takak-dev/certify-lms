<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 質問掲示板のスレッド一覧をフィルタ付きで取得するユースケース。手本: `Certification\IndexAction`。
 *
 * 表示行は `QaThread::scopeForUser($viewer)` でロール別に絞る
 * (admin = 公開停止資格を含む全件 / student = 公開中のみ / coach = 公開中かつ担当資格)。
 * 並びは新着順(チケット要件「スレッド一覧の表示(新着順、ページネーションあり)」)。
 *
 * 一覧カードが投稿者・資格・回答数を参照するため(`_thread-card.blade.php:6,14,21`)、
 * `with()` と `withCount()` で先読みする(要件「件数が増えても取得時間が線形に増えないように」)。
 */
final class IndexAction
{
    public function __invoke(
        User $viewer,
        ?QaThreadStatus $status,
        ?string $certificationId,
        ?string $keyword,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = QaThread::query()
            ->forUser($viewer)
            ->with(['user', 'certification'])
            ->withCount('replies')
            ->keyword($keyword);

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if ($certificationId !== null && $certificationId !== '') {
            $query->where('certification_id', $certificationId);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }
}
