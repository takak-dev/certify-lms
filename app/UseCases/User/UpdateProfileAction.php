<?php

declare(strict_types=1);

namespace App\UseCases\User;

use App\Http\Controllers\Settings\ProfileController;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * 本人のプロフィール更新ユースケース(氏名 / 自己紹介、コーチのみ固定面談 URL)。
 *
 * 対象は常にログイン中の本人。ルートがパラメータを持たないため本人検証は不要で、
 * 受け取る $attributes は UpdateProfileRequest::validated() の戻り値だけを想定する。
 * email / password / role / status は rules に無いため、この配列に混ざることはない。
 *
 * users.status を変えないので状態ログ(user_status_logs)は積まない(decisions #69 の基準)。
 *
 * ⚠️ 受け取った配列はそのまま update() へ渡さず、本 Action 側でも更新可能な列を絞る。
 *    User::$fillable には role / status が含まれる(User.php:35-36)ため、将来この Action を
 *    別の呼び出し元が $request->all() で再利用した瞬間に権限昇格が成立してしまう。
 *    FormRequest の rules に続く二重の守りとして、ここでもデフォルト拒否にする。
 *
 * @see ProfileController::update()
 */
final class UpdateProfileAction
{
    /**
     * @param array<string, mixed> $attributes UpdateProfileRequest::validated()
     */
    public function __invoke(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes): User {
            $user->update(Arr::only($attributes, ['name', 'bio', 'meeting_url']));

            return $user->refresh();
        });
    }
}
