<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Models\Payment;
use App\Models\User;

/**
 * 決済完了画面に出す購入記録を取得する(S-A-03)。
 *
 * ⚠️ **必ず本人の購入だけを引く。** session_id は URL に載る値なので、他人の cs_... を
 *    貼られる経路がある。Stripe の ID が推測困難であることを認可の代わりにしない
 *    (CLAUDE.md §3-7「画面から消したものは URL でも塞ぐ」と同じ考え方)。
 *
 * ⚠️ 見つからなくても例外にせず null を返す。支給 Blade が `@if ($payment)` で
 *    null を前提にしている(meeting-quota/success.blade.php:22)。ブックマークからの
 *    直アクセスや、Webhook より先にブラウザが戻ってきた場合もここを通る。
 */
final class CheckoutSuccessAction
{
    public function __invoke(User $user, ?string $sessionId): ?Payment
    {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }

        return Payment::query()
            ->where('stripe_checkout_session_id', $sessionId)
            // 持ち主で絞る。これが無いと他人の購入内容(金額・回数・状態)が見えてしまう
            ->where('user_id', $user->id)
            // 支給 Blade が $payment->meetingPack?->name を表示する(success.blade.php:25)
            ->with('meetingPack')
            ->first();
    }
}
