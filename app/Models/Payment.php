<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 追加面談パックの購入記録(S-A-03)。1 行 = 1 回の決済(Stripe Checkout Session 1 件)。
 *
 * 受講生が購入ボタンを押した時点で status = Pending の行を作り、Stripe からの Webhook を
 * 受けて Succeeded へ更新する(Failed にする経路はアプリに無い。decisions #228)。残面談回数が増えるのは Succeeded になった瞬間だけで、
 * 実際の加算は meeting_quota_transactions 側(type = purchased)に 1 行積む形で記録する。
 *
 * ⚠️ amount / quantity は購入時点の控え。meeting_packs の現在値を参照してはいけない
 *    (原典 共通の振る舞い「後からマスタを変更しても過去の購入を監査できる」)。
 *
 * 関連: User(購入者) / MeetingPack(購入した SKU)
 */
class Payment extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'meeting_pack_id',
        'amount',
        'quantity',
        'status',
        'paid_at',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
    ];

    /**
     * DB の生の値と PHP のオブジェクトの変換表。
     *
     * status を Enum に変換しないと、支給 Blade の
     * `$payment->status->label()`(meeting-pack/management/show.blade.php:162) が
     * 「文字列に対するメソッド呼び出し」で落ちる。paid_at も同様に
     * `$payment->paid_at?->format(...)`(同 :166) が Carbon を前提にしている。
     */
    protected $casts = [
        'status' => PaymentStatus::class,
        'amount' => 'integer',
        'quantity' => 'integer',
        'paid_at' => 'datetime',
    ];

    /**
     * 「売れた」とみなす決済状態(S-A-03 / decisions #232)。
     *
     * pending(決済画面で離脱しただけ)と failed は含めない —— 売れていないため。
     * ⚠️ 用途は**購入数の表示**に限る。削除の可否は状態を問わず payments の有無で判定する
     * (DB の restrictOnDelete と解釈を合わせるため。MeetingPack\DestroyAction 参照)。
     *
     * @return array<int, PaymentStatus>
     */
    public static function settledStatuses(): array
    {
        return [PaymentStatus::Succeeded, PaymentStatus::Refunded];
    }

    /**
     * 成立した購入(完了・返金済み)だけを絞り込むスコープ。
     *
     * @param Builder<Payment> $query
     *
     * @return Builder<Payment>
     */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereIn('status', self::settledStatuses());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MeetingPack, $this>
     */
    public function meetingPack(): BelongsTo
    {
        return $this->belongsTo(MeetingPack::class);
    }
}
