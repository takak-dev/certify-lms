<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingQuota\MeetingPackInvalidTransitionException;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックを公開する(Draft → Published)ユースケース。
 * 下書き以外からの遷移は不正で MeetingPackInvalidTransitionException(409)。
 *
 * 画面(show.blade.php)もボタンを状態で出し分けているが、それはブラウザの中だけの制御。
 * URL を直接叩かれた場合に効くのはこの判定。
 *
 * ⚠️ TODO(行ロック・pending-list): 状態の確認と UPDATE の間に行ロックが無い。管理者が2人同時に別の遷移を送ると、
 * どちらも自分の読んだ状態を正しいと思って更新しうる。手本の Certification/PublishAction も同じ形。
 * S-A-03 で判断した結果、本チケットでは見送った(decisions #229)。Certification 系ごと見直す前提で docs/pending-list.md に持ち越し済み。 *
 * 手本: app/UseCases/Certification/PublishAction.php
 */
final class PublishAction
{
    /**
     * @throws MeetingPackInvalidTransitionException 下書き以外からの呼出
     */
    public function __invoke(MeetingPack $plan, User $admin): MeetingPack
    {
        // $casts で Enum になっているので、文字列ではなくケースそのものと比べられる
        if ($plan->status !== MeetingPackStatus::Draft) {
            throw MeetingPackInvalidTransitionException::forPublish();
        }

        return DB::transaction(function () use ($plan, $admin) {
            $plan->update([
                'status' => MeetingPackStatus::Published->value,
                'updated_by_user_id' => $admin->id,
            ]);

            return $plan->fresh();
        });
    }
}
