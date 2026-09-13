<?php

declare(strict_types=1);

namespace App\Http\Requests\Plan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 受講プラン更新リクエスト。基本情報の5項目だけを更新できる。
 *
 * status は rules() に入れない。原典「編集: 基本情報を更新。状態は本フォームでは変更しない」に従い、
 * 状態の変更は詳細画面の遷移ボタン(publish / archive / unarchive)からのみ行う。
 * 支給 edit.blade.php にも status の入力欄が無い。
 *
 * 手本: app/Http/Requests/MeetingPack/UpdateRequest.php
 */
class UpdateRequest extends FormRequest
{
    /**
     * route('plan') は URL の {plan} が指す Plan。
     * Route::resource('plans', ...) の既定のパラメータ名がそのまま 'plan' になっている。
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
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'default_meeting_quota' => ['required', 'integer', 'min:0', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
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
