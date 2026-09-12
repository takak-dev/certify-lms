<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreAvatarRequest;
use App\UseCases\User\DestroyAvatarAction;
use App\UseCases\User\StoreAvatarAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 本人のアバター画像 Controller(アップロード / 削除)。
 *
 * ルートがパラメータを持たない(/settings/avatar)ため、対象は常にログイン中の本人になる。
 * 他人を指す手段が無いので Policy は設けない(routes/web.php の設定グループのコメント参照)。
 * 画面は「プロフィールタブの右カラム」なので、成功後は tab を付けずにプロフィールタブへ戻す。
 */
class AvatarController extends Controller
{
    public function store(StoreAvatarRequest $request, StoreAvatarAction $action): RedirectResponse
    {
        // file() は検証済みの UploadedFile を返す(rules に avatar があるため必ず存在する)
        $action($request->user(), $request->file('avatar'));

        return redirect()
            ->route('settings.profile.edit')
            ->with('success', 'アイコン画像を更新しました。');
    }

    /**
     * 削除は入力を受け取らないため FormRequest を挟まない(検証する項目が無い)。
     * 未設定の状態で送られても Action 側が null 安全に処理する。
     */
    public function destroy(Request $request, DestroyAvatarAction $action): RedirectResponse
    {
        $action($request->user());

        return redirect()
            ->route('settings.profile.edit')
            ->with('success', 'アイコン画像を削除しました。');
    }
}
