<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * admin 用の面談パック一覧の絞り込みリクエスト。keyword / status の 2 種フィルタ。
 *
 * 一覧は URL パラメータを受け取るだけだが、FormRequest を挟むことで
 * ①不正な status を弾く ②検索語の長さを制限する ③認可をここで済ませる、の3つを一度に行う。
 * 手本: app/Http/Requests/Certification/IndexRequest.php
 */
class IndexRequest extends FormRequest
{
    /**
     * 一覧を開いてよいかの判定。ここで false を返すと 403 になる。
     * `?->` と `?? false` は「未ログインで user() が null」のときに落ちないようにするため。
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', MeetingPack::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // 検索欄の maxlength="100"(index.blade.php)に合わせる
            'keyword' => ['nullable', 'string', 'max:100'],
            // Rule::enum は「Enum のケースの値(draft / published / archived)のどれか」を検査する
            'status' => ['nullable', Rule::enum(MeetingPackStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * エラー文に出る項目名。Store / Update と同じく日本語にする。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'keyword' => '検索キーワード',
            'status' => '状態',
        ];
    }
}
