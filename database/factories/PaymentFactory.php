<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 *
 * 追加面談パックの購入記録(S-A-03)。
 *
 * ⚠️ 既定は pending(まだ支払われていない)。実際の購入もこの状態から始まる
 *    (CreateAction が pending で作り、Webhook が succeeded / failed に変える)。
 *
 * ⚠️ amount / quantity は「購入時点の控え」なので、パックの現在値をそのまま写すのは
 *    Factory の既定としてだけ。マスタを変えた後の購入を再現したいテストでは明示的に上書きする。
 *
 * 手本: database/factories/MeetingPackFactory.php(状態ごとに state を生やす形)
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $pack = MeetingPack::factory();

        return [
            'user_id' => User::factory(),
            'meeting_pack_id' => $pack,
            // 親の値を写す。MeetingPack::factory() は price = meeting_count × 2,500〜3,500 で作る
            'amount' => fn (array $attributes) => MeetingPack::find($attributes['meeting_pack_id'])?->price ?? 3000,
            'quantity' => fn (array $attributes) => MeetingPack::find($attributes['meeting_pack_id'])?->meeting_count ?? 1,
            'status' => PaymentStatus::Pending->value,
            'paid_at' => null,
            // Stripe が発行する ID の形に寄せたダミー(cs_test_... / pi_test_...)。
            // unique 制約があるので、行ごとに必ず違う値にする。
            'stripe_checkout_session_id' => 'cs_test_'.Str::lower(Str::random(24)),
            'stripe_payment_intent_id' => null,
        ];
    }

    /**
     * 決済完了。⚠️ この state だけでは残面談回数は増えない。
     * 残数は meeting_quota_transactions の合計で決まるため、台帳に purchased の行を
     * 積んで初めて反映される(MeetingQuotaService::remaining)。
     */
    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Succeeded->value,
            'paid_at' => now(),
            'stripe_payment_intent_id' => 'pi_test_'.Str::lower(Str::random(24)),
        ]);
    }

    /**
     * 決済を開始したが完了していない。CreateAction が作る状態と同じ。
     */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Pending->value,
            'paid_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Failed->value,
            'paid_at' => null,
        ]);
    }

    /**
     * Stripe 側で返金済み(decisions #210)。台帳には payment_refunded のマイナス行が対応する。
     */
    public function refunded(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Refunded->value,
            'paid_at' => now()->subDay(),
            'stripe_payment_intent_id' => 'pi_test_'.Str::lower(Str::random(24)),
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function forPack(MeetingPack $pack): static
    {
        return $this->state(fn () => [
            'meeting_pack_id' => $pack->id,
            'amount' => $pack->price,
            'quantity' => $pack->meeting_count,
        ]);
    }
}
