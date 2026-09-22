<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingQuota;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 追加面談パックの購入開始リクエスト(S-A-03)。
 *
 * 認可は ['auth', 'role:student', 'active-learning'] middleware が担う。
 * ルートパラメータを持たず、作られる payments 行の持ち主は常に $request->user() 本人なので、
 * 他人を指す場所がどこにも無い(手本: GoogleCalendar/RedirectRequest —— 同じ理由で authorize() は true)。
 *
 * ⚠️ 「そのパックが公開中か」はここで見ない。入力の形式ではなく**送信時点のデータの状態**であり、
 *    S-B-02 が状態を理由にした拒否をすべて Action 側に寄せているため(docs/tickets/S-A-03.md 申し送り①)。
 *    判定は CreateAction が行い、違反は 409 で「購入できません」と返す。
 *
 * @see MeetingQuotaCheckoutController::create()
 */
class CheckoutCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 支給 Blade が送ってくるのは隠しフィールド 1 つだけ
     * (meeting-quota/checkout-select.blade.php:54「meeting_pack_id」)。
     *
     * exists: で実在も確認する。存在しない ID を手で送られた場合、Action 側で findOrFail の
     * 404 になるより、入力エラーとして購入画面に戻すほうが画面の流れとして自然。
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'meeting_pack_id' => ['required', 'ulid', 'exists:meeting_packs,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'meeting_pack_id' => '面談パック',
        ];
    }
}
