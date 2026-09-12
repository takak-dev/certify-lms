<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingQuota\MeetingPackNotDeletableException;
use App\Models\MeetingPack;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックを物理削除するユースケース(原典「削除(物理削除...)」)。
 *
 * 公開中は削除できない。画面(show.blade.php:95)も公開中のときは削除ボタンを出さないが、
 * それはブラウザの中だけの制御で、URL を直接叩けば届いてしまう。ここが本当の防御。
 *
 * ⚠️ 購入履歴があるパックも削除不可にする(decisions #38)。ただし App\Models\Payment は
 * S-A-03(Stripe 連携)で作るため、その判定は S-A-03 でここに追加する(decisions #123)。
 * 現時点では購入の仕組み自体が無く、購入履歴を持つパックは存在しえない。
 *
 * 手本: app/UseCases/CertificationCategory/DestroyAction.php
 */
final class DestroyAction
{
    /**
     * @throws MeetingPackNotDeletableException 公開中である
     */
    public function __invoke(MeetingPack $plan): void
    {
        if ($plan->status === MeetingPackStatus::Published) {
            throw new MeetingPackNotDeletableException;
        }

        DB::transaction(fn () => $plan->delete());
    }
}
