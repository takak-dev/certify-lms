<?php

declare(strict_types=1);

namespace App\Enums;

enum MeetingQuotaTransactionType: string
{
    case GrantedInitial = 'granted_initial';
    case Purchased = 'purchased';
    // 決済の返金にともなう回数の取り消し(S-A-03 / decisions #210)。amount は常にマイナス。
    // ⚠️ 下の Refunded(ラベル「返却」)とは別物。あちらは面談キャンセル時の +1 で、
    //    紐づく先も related_meeting_id。こちらは related_payment_id に紐づく。
    case PaymentRefunded = 'payment_refunded';
    case Consumed = 'consumed';
    case Refunded = 'refunded';
    case AdminGrant = 'admin_grant';

    public function label(): string
    {
        return match ($this) {
            self::GrantedInitial => '初期付与',
            self::Purchased => '購入',
            self::PaymentRefunded => '返金',
            self::Consumed => '消費',
            self::Refunded => '返却',
            self::AdminGrant => '管理者付与',
        };
    }
}
