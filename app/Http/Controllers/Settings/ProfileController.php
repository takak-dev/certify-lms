<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateProfileRequest;
use App\UseCases\User\UpdateProfileAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 本人のプロフィール設定 Controller(氏名 / 自己紹介、コーチのみ固定面談 URL)。
 *
 * ルートがパラメータを持たない(/settings/profile)ため、対象は常にログイン中の本人になる。
 * 他人を指す手段が無いので Policy は設けない(routes/web.php の設定グループのコメント参照)。
 * メールアドレスは画面で readonly。UpdateProfileRequest の rules に含めないため、
 * フォームを改ざんして送られても validated() に乗らず更新されない。
 */
class ProfileController extends Controller
{
    /**
     * プロフィール設定画面(タブ切替のホスト)を表示する。
     *
     * Blade は $user だけを使う(resources/views/settings/profile.blade.php:36-39,49)。
     * タブの出し分けは Blade 側が ?tab= を読んで行うので、Controller は何も渡さない。
     */
    public function edit(Request $request): View
    {
        return view('settings.profile', ['user' => $request->user()]);
    }

    public function update(UpdateProfileRequest $request, UpdateProfileAction $action): RedirectResponse
    {
        $action($request->user(), $request->validated());

        return redirect()
            ->route('settings.profile.edit')
            ->with('success', 'プロフィールを更新しました。');
    }
}
