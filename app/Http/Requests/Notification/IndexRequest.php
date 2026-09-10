<?php

declare(strict_types=1);

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 通知一覧の絞り込みリクエスト。受け取るのはタブ(全件 / 未読のみ)とページ番号だけ。
 */
class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 引くのは自分宛の通知だけ(IndexAction が $user->notifications() で絞る)なので、
        // 「誰の通知を見てよいか」を判定する必要が無い。よって Policy は使わず、認証済みかだけを見る
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // x-tabs が URL に付ける ?tab= の値。
            // 使ってよいキーは画面側が決めている(resources/views/notifications/index.blade.php:32)
            'tab' => ['nullable', Rule::in(['all', 'unread'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * エラーメッセージに出す項目名。
     * これが無いと URL のクエリ名（tab / page）がそのまま画面に出る。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tab' => '表示タブ',
            'page' => 'ページ番号',
        ];
    }
}
