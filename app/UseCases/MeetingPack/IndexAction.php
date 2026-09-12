<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * admin 用の面談パック一覧をフィルタ付きで取得するユースケース。
 *
 * 並び順は Model の scopeOrdered()(並び順の昇順 → 作成日時の降順)をそのまま使う。
 * 原典は一覧の並び順を指定していないが、支給 Model が既にこのスコープを持っており、
 * 受講生向けの購入動線(FetchStudentDashboardAction)も同じ並びで表示している。
 *
 * ⚠️ 一覧の「購入数」列(meeting-pack/management/index.blade.php:104,135)は withCount('payments') を付けていない。
 * App\Models\Payment は S-A-03(Stripe 連携)で作るため現時点では数えられず、
 * 支給 Blade が `$plan->payments_count ?? 0` と書いているので 0 件と表示される。
 * ⚠️ 例外にならないので気付きにくい。S-A-03 でここに withCount を足すこと(decisions #124)。
 *
 * 手本: app/UseCases/Certification/IndexAction.php
 */
final class IndexAction
{
    /**
     * @param int $perPage 1 ページの表示件数。ページングする既存の一覧処理はすべて 20 件(decisions #64 / #94)
     */
    public function __invoke(
        ?string $keyword,
        ?MeetingPackStatus $status,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = MeetingPack::query();

        // 空文字は「絞り込まない」と同じ扱い。検索欄を空で送信しても全件が出る
        if ($keyword !== null && $keyword !== '') {
            $query->where('name', 'LIKE', '%'.$keyword.'%');
        }

        if ($status !== null) {
            // DB のカラムと比べるので Enum そのものではなく ->value(文字列)を渡す
            $query->where('status', $status->value);
        }

        return $query
            ->ordered()
            ->paginate($perPage)
            // withQueryString: 2 ページ目へ進んでも keyword / status が URL から消えないようにする
            ->withQueryString();
    }
}
