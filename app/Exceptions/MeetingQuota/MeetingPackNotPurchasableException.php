<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingQuota;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 公開中でない面談パックを購入しようとした際の例外(HTTP 409)。
 * `MeetingQuota\CheckoutCreateAction` が throw する。
 *
 * 原典 要件「公開中でない面談パックは、購入動線に並ばないだけでなく、
 * URL を直接指定しても購入できない」を担保する最後の砦。購入ボタンが画面に無くても
 * POST /meeting-quota/checkout に meeting_pack_id を手で送ればリクエスト自体は届くため、
 * 画面の出し分けだけでは要件を満たさない(CLAUDE.md §3-7)。
 *
 * 「公開中か」は入力の形式ではなく送信時点のデータの状態なので、FormRequest ではなく
 * Action で判定する(S-B-02 が状態を理由にした拒否を Action へ揃えている)。
 *
 * 409 は app/Exceptions/Handler.php が拾い、直前の画面へ戻して error フラッシュを出す。
 * Controller 側で try-catch を書く必要はない。
 *
 * 手本: app/Exceptions/MeetingQuota/InsufficientMeetingQuotaException.php
 *      (文言が 1 つなので公開コンストラクタで固定する形。文言が複数になったら
 *       MeetingPackNotDeletableException のように private + static ファクトリへ変える)
 */
final class MeetingPackNotPurchasableException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('この面談パックは現在購入できません。', $previous);
    }
}
