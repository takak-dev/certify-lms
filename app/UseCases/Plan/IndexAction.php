<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * admin 用の受講プラン一覧をフィルタ付きで取得するユースケース。
 *
 * 並び順は Model の scopeOrdered()(並び順の昇順 → 作成日時の降順)をそのまま使う。
 * 原典は一覧の並び順を指定していないが、支給 Model が既にこのスコープを持っており、
 * 支給 Blade の並び順の案内も「小さい順に表示」(plan/management/create.blade.php:73)と書いている。
 *
 * 「受講者数」列(plan/management/index.blade.php:135 の $plan->users_count)は
 * 契約中(受講中 + 招待中)だけを数える。定義は User::scopeContracted()(decisions #126)。
 *
 * 手本: app/UseCases/MeetingPack/IndexAction.php
 */
final class IndexAction
{
    /**
     * @param int $perPage 1 ページの表示件数。ページングする既存の一覧処理はすべて 20 件(decisions #64 / #94)
     */
    public function __invoke(
        ?string $keyword,
        ?PlanStatus $status,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = Plan::query()
            // 'users' と書くと Blade が読む $plan->users_count に集計結果が入る。
            // 無条件の withCount('users') ではなく、契約中だけを数えるため条件を付けている
            ->withCount([
                'users' => fn ($q) => $q->contracted(),
            ]);

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
