<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/**
 * 本人のパスワード変更 Controller。
 *
 * ⚠️ このチケットで唯一 FormRequest を挟まない。支給された UpdateUserPassword が自クラス内で
 *    Validator を回し、`updatePassword` という名前付きエラーバッグに詰めるため(同 :30-35)。
 *    支給 Blade(tab-password.blade.php:7-8)はそのバッグ名を読む。FormRequest を挟むと検証が二重になり、
 *    エラーが既定のバッグへ入って画面に表示されなくなる。
 *
 * Fortify 既定の PUT /user/password ルートは登録しない(config/fortify.php:159-161 の支給コメントの指示)。
 * 委譲先は FortifyServiceProvider::boot() の updateUserPasswordsUsing() が束ねた実装。
 */
class PasswordController extends Controller
{
    public function update(Request $request, UpdatesUserPasswords $updater): RedirectResponse
    {
        // 生の入力をそのまま渡す。current_password / password / password_confirmation の検証と
        // ハッシュ化は委譲先が行う。ここで検証しないのは上記の理由による。
        $updater->update($request->user(), $request->all());

        // パスワード変更後もログアウトさせない(支給 Action にセッション操作が無く、他端末も切らない)。
        // 変更したタブに戻し、そのままフラッシュメッセージを読めるようにする。
        return redirect()
            ->route('settings.profile.edit', ['tab' => 'password'])
            ->with('success', 'パスワードを変更しました。');
    }
}
