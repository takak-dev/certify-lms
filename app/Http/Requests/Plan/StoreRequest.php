<?php

declare(strict_types=1);

namespace App\Http\Requests\Plan;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 受講プラン新規作成リクエスト。admin がプラン名 / 説明 / 受講期間 / 初期付与面談回数 / 並び順を入力する。
 *
 * status は rules() に入れない。新規は必ず「下書き」で作られ(plan/management/create.blade.php:81 のボタンが
 * 「下書きとして保存」)、状態の変更は詳細画面の遷移ボタンからのみ行う。
 * ルールに書かない項目は validated() に乗らないので、フォームを改ざんして送っても DB に届かない。
 *
 * 手本: app/Http/Requests/MeetingPack/StoreRequest.php
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Plan::class) ?? false;
    }

    /**
     * 範囲の根拠は支給 Blade の hint と maxlength。
     * 受講期間 1 〜 3650 と面談回数 0 〜 1000 は create.blade.php:53,63 の hint がそのまま数字を書いている
     * (DB の unsignedSmallInteger = 65535 より厳しい業務上の制限)。
     * 並び順の上限 65535 だけは Blade に書かれておらず、DB の型(unsignedInteger = 約42億)とも無関係。
     * 既存の出題分野マスタ・模試マスタ・面談パックに合わせた。
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'default_meeting_quota' => ['required', 'integer', 'min:0', 'max:1000'],
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
            'name' => 'プラン名',
            'description' => '説明',
            'duration_days' => '受講期間(日)',
            'default_meeting_quota' => '初期付与面談回数',
            'sort_order' => '並び順',
        ];
    }
}
