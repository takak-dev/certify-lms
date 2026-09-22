<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingPack;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 面談パック更新リクエスト。基本情報の6項目だけを更新できる。
 *
 * status は rules() に入れない。原典「編集(基本情報の更新。状態はこのフォームでは変更しない)」に従い、
 * 状態の変更は詳細画面の遷移ボタン(publish / archive / unarchive)からのみ行う。
 * 支給 edit.blade.php にも status の入力欄が無い。
 */
class UpdateRequest extends FormRequest
{
    /**
     * route('plan') は URL の {plan} が指す MeetingPack。
     * ルート定義で parameters(['meeting-packs' => 'plan']) と名前を変えているので、ここも 'plan'。
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('plan')) ?? false;
    }

    /**
     * 検証内容は新規作成と同じ。範囲の根拠は StoreRequest のコメントを参照。
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'meeting_count' => ['required', 'integer', 'min:1', 'max:100'],
            // 下限 100 円(decisions #212・面談3 Q65 で PM が指定)。
            // ⚠️ 実測(2026-09-22): Stripe は JPY の合計額が ¥50 未満の Checkout Session を
            //    作れない(「must add up to at least ¥50 JPY」)。¥1〜¥49 のパックを公開すると
            //    購入ボタンを押した受講生が 409 で止まる。100 円ならその範囲より上にある。
            'price' => ['required', 'integer', 'min:100', 'max:1000000'],
            'stripe_price_id' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'SKU 名',
            'description' => '説明',
            'meeting_count' => '面談回数',
            'price' => '価格',
            'stripe_price_id' => 'Stripe Price ID',
            'sort_order' => '並び順',
        ];
    }
}
