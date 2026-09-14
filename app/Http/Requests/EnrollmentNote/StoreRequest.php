<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentNote;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 受講生メモの追加リクエスト。受講登録詳細画面に埋め込まれたフォームから届く。
 *
 * 認可は `EnrollmentNotePolicy::create()` に委ね、親の受講登録を第2引数で渡す
 * (支給 Blade も同じ形で判定している: enrollment-note/_list.blade.php:15)。
 * まだメモの行が無いので、判定対象はメモではなく親になる。
 *
 * 手本: app/Http/Requests/EnrollmentGoal/StoreRequest.php(同じ「受講登録配下への追加」の形)。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // route('enrollment') はルート定義の {enrollment} と対応する。
        // モデルバインディングで解決済みの Enrollment が入っている
        //
        // ⚠️ 解除済み(論理削除済み)の受講登録はここまで届かない。ルートに ->withTrashed() を
        //    付けていないため、モデルバインディングが解決できず 404 になる(decisions #132)。
        $enrollment = $this->route('enrollment');

        return $enrollment instanceof Enrollment
            && ($this->user()?->can('create', [EnrollmentNote::class, $enrollment]) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // 原典「本文には文字数制限があり、空では追加できない」。
            // 上限 2000 は画面の属性に合わせた(enrollment-note/_list.blade.php:24)。
            //
            // ⚠️ 追加フォームには :required が書かれていないが(編集フォームには :required="true" がある)、
            //    原典が「空では追加できない」と明記しているので両方とも必須にする。
            //    画面側の novalidate により、どちらにせよ検証はサーバ側が担う。
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * エラーメッセージに出す項目名。画面のラベルと同じ言葉にする
     * (enrollment-note/_list.blade.php:20 の label="新規メモ")。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => '新規メモ',
        ];
    }
}
