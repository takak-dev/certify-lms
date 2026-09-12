<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 本人のアバター画像アップロードのリクエスト。png / jpg / jpeg / webp の 2MB 以下に制限する。
 *
 * 認可は常に true。ルートがパラメータを持たず対象が常にログイン中の本人になるため
 * (routes/web.php の設定グループのコメント参照)。
 *
 * 制限値は支給 Blade が根拠。tab-profile.blade.php:93 の accept 属性が png / jpeg / webp、
 * 同 :95 の hint が「PNG / JPG / WebP、2MB 以内」。フィールド名 avatar も同 :91 に合わせる
 * (教材内画像の手本は file だが、こちらの画面は avatar で送ってくる)。
 */
class StoreAvatarRequest extends FormRequest
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
            // max は KB 単位。2048KB = 2MB。
            // mimes は拡張子ではなくファイルの中身から MIME タイプを判定する(拡張子の偽装を防ぐ)。
            'avatar' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'avatar' => 'アイコン画像',
        ];
    }
}
