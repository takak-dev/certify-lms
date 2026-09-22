<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 追加面談パック購入 (Payment) の決済状態を表す Enum。
 *
 * 状態は Stripe 側の決済結果を写したもので、アプリから任意に遷移させるものではない。
 *
 * - [*] → Pending  : 受講生が購入ボタンを押し、Checkout Session を作った時点(まだ支払われていない)
 * - Pending → Succeeded: Webhook `checkout.session.completed` を受信(このときだけ残面談回数を加算する)
 * - Pending → Failed   : ⚠️ **この遷移を起こすコードは無い**(decisions #228)。失敗系の Webhook を
 *                        購読していないため、Failed になるのは Seeder が投入した初期データだけ。
 *                        ケースとして残すのは、支給 Blade
 *                        resources/views/meeting-pack/management/show.blade.php:21-26 の
 *                        default 無し match が 4 ケースを列挙しており、減らすと画面が落ちるため
 * - Succeeded → Refunded: 管理者が Stripe ダッシュボードで返金した通知を受信(decisions #210 で残数を減らす)
 *
 * ⚠️ アプリ側に返金の動線は作らない(decisions #197)。Refunded は「Stripe で起きた返金を写す」ためだけに存在する。
 *
 * ⚠️ 4 ケースは固定。支給 Blade resources/views/meeting-pack/management/show.blade.php:21-26 の
 *    match が 4 ケースすべてを列挙しており default を持たないため、ケースを減らすと
 *    面談パック詳細画面が UnhandledMatchError で 500 になる。
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '保留',
            self::Succeeded => '完了',
            self::Failed => '失敗',
            self::Refunded => '返金済み',
        };
    }
}
