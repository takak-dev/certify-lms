<?php

declare(strict_types=1);

namespace App\UseCases\User;

use App\Http\Controllers\Settings\AvatarController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 本人のアバター画像削除ユースケース。
 *
 * users.avatar_url を null に戻してから、commit 後に実ファイルを削除する。
 * ROLLBACK 時は Storage 削除をスキップし、不可逆な実ファイル削除を防ぐ。
 * 削除後は <x-avatar> が氏名のイニシャル表示に戻る(avatar.blade.php:32-40)。
 *
 * 手本: App\UseCases\SectionImage\DestroyAction
 *
 * @see AvatarController::destroy()
 */
final class DestroyAvatarAction
{
    use HasAvatarStorage;

    public function __invoke(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            // 実ファイルの特定は DB を更新する前に済ませる(更新後は avatar_url が null になる)
            $path = $this->storagePathOf($user);

            $user->update(['avatar_url' => null]);

            if ($path !== null) {
                $this->deleteAfterCommit($path);
            }

            return $user->refresh();
        });
    }

    /**
     * 実ファイルの削除を commit 後に予約する。ROLLBACK した場合は実行されない(不可逆な削除を防ぐ)。
     *
     * ⚠️ コールバックは commit 処理の内側で走るため、ここで例外が出ると DB が既にコミット済み
     *    (avatar_url は null になった)なのに 500 が返る。実ファイルが残るだけで画面は未設定表示に
     *    戻っており実害が無いので、握りつぶして report に回す(StoreAvatarAction と同じ形)。
     */
    private function deleteAfterCommit(string $path): void
    {
        DB::afterCommit(function () use ($path): void {
            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
