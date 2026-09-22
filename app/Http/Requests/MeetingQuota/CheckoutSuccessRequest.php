<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingQuota;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 決済完了画面のリクエスト(S-A-03)。
 *
 * Stripe が success_url の `{CHECKOUT_SESSION_ID}` を実際の cs_... に置き換えて
 * `?session_id=cs_...` として返してくる。
 *
 * ⚠️ session_id は **必須にしない**。ブックマークや直打ちで来ることがあり、その場合でも
 *    支給 Blade は購入サマリを出さずに完了画面を描ける作りになっている
 *    (meeting-quota/success.blade.php:22 の `@if ($payment)`)。
 *
 * ⚠️ この ID は URL に載るので、**持っている人＝見てよい人ではない**。誰の購入かは
 *    CheckoutSuccessAction が user_id で絞って判定する(推測不能な ID を認可の代わりにしない)。
 *
 * 手本: GoogleCalendar/RedirectRequest —— GET のクエリでも FormRequest を使い、
 *      スカラー以外を prepareForValidation() で均す先例。
 *
 * @see MeetingQuotaCheckoutController::success()
 */
class CheckoutSuccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'session_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * ⚠️ `?session_id[]=a` のような配列を渡されても落ちないように、検証の前に
     *    スカラー以外を null に均す。ここで失敗させると完了画面が出せなくなり、
     *    「支払ったのにエラー画面」という最悪の見え方になる。
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('session_id') && ! is_string($this->input('session_id'))) {
            $this->merge(['session_id' => null]);
        }
    }
}
