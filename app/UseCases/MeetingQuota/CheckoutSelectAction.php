<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Models\MeetingPack;
use Illuminate\Database\Eloquent\Collection;

/**
 * 購入画面に並べる面談パックを取得する(S-A-03)。
 *
 * 原典 要件「公開中の面談パック一覧を閲覧できる(名前 / 面談回数 / 価格)」。
 * 公開中でないパックはここに並ばず、URL を直接指定しても購入できない(その門番は CreateAction 側)。
 */
final class CheckoutSelectAction
{
    /**
     * @return Collection<int, MeetingPack>
     */
    public function __invoke(): Collection
    {
        // published() / ordered() は MeetingPack のローカルスコープ(MeetingPack.php:87,97)。
        // scopePublished / scopeOrdered の "scope" を外した名前で呼ぶのが Eloquent の慣習。
        // 並びは sort_order の昇順が主、同値なら created_at の降順。
        return MeetingPack::query()
            ->published()
            ->ordered()
            ->get();
    }
}
