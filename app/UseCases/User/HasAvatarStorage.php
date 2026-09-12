<?php

declare(strict_types=1);

namespace App\UseCases\User;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * アバター画像の保存先に関する共通知識。
 * Action だけでなく Seeder からも使う共有の契約(StoreAvatarAction / DestroyAvatarAction / AvatarSeeder)。
 *
 * users.avatar_url は `<img src>` にそのまま入る公開 URL を保持する(decisions #45)ため、
 * 実ファイルを操作するには URL から public disk 上の相対パスへ戻す必要がある。
 * 教材内画像(SectionImage)は DB に相対パスを持つのでこの変換が要らない — 本 trait はその差を吸収する。
 *
 * ⚠️ 定数ではなくメソッドで値を返すのは、trait の定数が PHP 8.2 以降の機能で、
 *    composer.json の制約が "php": "^8.1" のため(既存 trait 3 本もメソッドのみで構成されている)。
 */
trait HasAvatarStorage
{
    /** public disk 上の保存先ディレクトリ。 */
    private function avatarDirectory(): string
    {
        return 'avatars';
    }

    /**
     * public disk 上の相対パスから、avatar_url に入れる公開 URL を組み立てる。
     * 既存の教材内画像も同じ形で `/storage/{path}` を組み立てている(SectionImageController.php:25)。
     * Storage::url() は APP_URL 込みの絶対 URL を返すため使わない(APP_URL を変えると既存行が壊れる)。
     */
    private function avatarPublicUrl(string $path): string
    {
        return '/storage/'.$path;
    }

    /**
     * users.avatar_url から public disk 上の相対パスを取り出す。
     * 自分が書いた形式(`/storage/avatars/...`)以外は対象外として null を返し、
     * 外部 URL や想定外の値を誤って削除しないようにする。
     */
    private function storagePathOf(User $user): ?string
    {
        $url = $user->avatar_url;
        $prefix = $this->avatarPublicUrl($this->avatarDirectory().'/');

        if ($url === null || ! str_starts_with($url, $prefix)) {
            return null;
        }

        return Str::after($url, '/storage/');
    }
}
