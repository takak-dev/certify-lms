<?php

declare(strict_types=1);

namespace App\UseCases\User;

use App\Exceptions\User\AvatarStorageException;
use App\Http\Controllers\Settings\AvatarController;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 本人のアバター画像アップロードユースケース。
 *
 * `avatars/{ulid}.{ext}` 形式で public disk に保存し、users.avatar_url に公開 URL を書く(decisions #45)。
 * Storage 保存と DB UPDATE を単一トランザクションで実行し、失敗時は ROLLBACK + 保険的に新ファイルを削除して
 * orphan ファイルを残さない。差し替え時の旧ファイルは commit 後に削除する(ROLLBACK 時は消さない)。
 *
 * 手本: App\UseCases\SectionImage\StoreAction
 *
 * @see AvatarController::store()
 */
final class StoreAvatarAction
{
    use HasAvatarStorage;

    /**
     * @throws AvatarStorageException
     */
    public function __invoke(User $user, UploadedFile $file): User
    {
        // ファイル名は ULID で採番する。元のファイル名を使うと衝突や危険な文字が混入するため。
        $ulid = (string) Str::ulid();

        // ⚠️ 拡張子は「送信者が付けたファイル名」ではなく「ファイルの中身」から決める。
        //    getClientOriginalExtension() は中身と無関係な文字列を返すため、中身が PNG で名前が
        //    icon.html のファイルを .html として保存してしまう(StoreAvatarRequest の mimes は中身を見るので
        //    検証を通過する。Laravel が名前で弾くのは .php 系だけ)。その結果 /storage/avatars/{ulid}.html が
        //    このアプリ自身のドメインで HTML として配信され、受講生が任意のページを設置できてしまう。
        //
        //    判定には guessExtension() を使う。mimes ルールが照合に使っているのと同じ関数なので
        //    (ValidatesAttributes::validateMimes)、検証を通ったファイルがここで弾かれることが構造上ない。
        //    実測: image/png => png / image/jpeg => jpg / image/webp => webp。
        //    default に落ちるのは許可形式を増やし忘れたときだけで、そのときは利用者の入力ミスではなく
        //    プログラムの不具合なので 500 で止めてよい。
        $ext = match ($file->guessExtension()) {
            'png' => 'png',
            'jpg' => 'jpg',
            'webp' => 'webp',
            default => throw new AvatarStorageException,
        };

        $directory = $this->avatarDirectory();
        $filename = "{$ulid}.{$ext}";
        $path = $directory.'/'.$filename;

        // 差し替え前の実ファイル。保存が確定してから消す
        $previousPath = $this->storagePathOf($user);

        try {
            return DB::transaction(function () use ($user, $file, $directory, $path, $filename, $previousPath): User {
                // public disk は config/filesystems.php:53 が 'throw' => false のため、保存に失敗しても
                // 例外ではなく false が返る。戻り値を見ないと「実ファイルが無い URL」を DB に書いてしまう
                if (Storage::disk('public')->putFileAs($directory, $file, $filename) === false) {
                    throw new AvatarStorageException;
                }

                $user->update(['avatar_url' => $this->avatarPublicUrl($path)]);

                if ($previousPath !== null) {
                    $this->deleteAfterCommit($previousPath);
                }

                return $user->refresh();
            });
        } catch (AvatarStorageException $e) {
            // 自分で投げたものは包み直さない(同じクラスの入れ子がログに残るのを避ける)
            Storage::disk('public')->delete($path);

            throw $e;
        } catch (\Throwable $e) {
            // DB 側が ROLLBACK されても Storage は戻らないため、保険で新ファイルを消す
            Storage::disk('public')->delete($path);

            throw new AvatarStorageException($e);
        }
    }

    /**
     * 旧ファイルの削除を commit 後に予約する。ROLLBACK した場合は実行されない(不可逆な削除を防ぐ)。
     *
     * ⚠️ コールバックは commit 処理の内側＝上位の try の中で走る。ここで例外が出ると、DB が既に
     *    コミット済みなのに catch 節が新ファイルを消してしまい、参照切れの URL が残る。
     *    旧ファイルが消えないこと自体に実害は無いので、この中で握りつぶして report に回す。
     */
    private function deleteAfterCommit(string $previousPath): void
    {
        DB::afterCommit(function () use ($previousPath): void {
            try {
                Storage::disk('public')->delete($previousPath);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
