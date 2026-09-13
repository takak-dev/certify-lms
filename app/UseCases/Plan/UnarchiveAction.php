<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanInvalidTransitionException;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 受講プランを下書きへ戻す(Archived → Draft)ユースケース。
 * アーカイブ済み以外からの遷移は不正で PlanInvalidTransitionException(409)。
 *
 * 画面(plan/management/show.blade.php)もボタンを状態で出し分けているが、それはブラウザの中だけの制御。
 * URL を直接叩かれた場合に効くのはこの判定。
 *
 * ⚠️ TODO(S-A-03): 状態の確認と UPDATE の間に行ロックが無い。管理者が2人同時に別の遷移を送ると、
 * どちらも自分の読んだ状態を正しいと思って更新しうる。手本の MeetingPack / Certification 系も同じ形。
 * S-A-03 で Certification 系ごと lockForUpdate() を見直す。
 *
 * 手本: app/UseCases/MeetingPack/UnarchiveAction.php
 */
final class UnarchiveAction
{
    /**
     * @throws PlanInvalidTransitionException アーカイブ済み以外からの呼出
     */
    public function __invoke(Plan $plan, User $admin): Plan
    {
        // $casts で Enum になっているので、文字列ではなくケースそのものと比べられる
        if ($plan->status !== PlanStatus::Archived) {
            throw PlanInvalidTransitionException::forUnarchive();
        }

        return DB::transaction(function () use ($plan, $admin) {
            $plan->update([
                'status' => PlanStatus::Draft->value,
                'updated_by_user_id' => $admin->id,
            ]);

            return $plan->fresh();
        });
    }
}
