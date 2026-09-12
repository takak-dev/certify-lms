<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingPack;

use App\Models\MeetingPack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 面談パック新規作成リクエスト。admin が SKU 名 / 説明 / 面談回数 / 価格 / Price ID / 並び順を入力する。
 *
 * status は rules() に入れない。新規は必ず「下書き」で作られ(create.blade.php のボタンが
 * 「下書きとして保存」)、状態の変更は詳細画面の遷移ボタンからのみ行う。
 * ルールに書かない項目は validated() に乗らないので、フォームを改ざんして送っても DB に届かない。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MeetingPack::class) ?? false;
    }

    /**
     * 名前 / 説明 / 面談回数 / 価格 / Price ID の範囲は、支給 Blade の hint と DB の型から決めている。
     * 並び順の上限 65535 だけは別で、DB の型(unsignedInteger = 約42億)とは無関係。
     * 既存の出題分野マスタ・模試マスタに合わせた(QuestionCategory / MockExam)。
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'meeting_count' => ['required', 'integer', 'min:1', 'max:100'],
            'price' => ['required', 'integer', 'min:0', 'max:1000000'],
            'stripe_price_id' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * エラー文に出る項目名。指定しないと「name は必須です」のように英語の列名が出る。
     *
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
