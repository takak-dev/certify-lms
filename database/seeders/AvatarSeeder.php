<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\UseCases\User\HasAvatarStorage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * 開発用のアバター画像シード(S-B-06)。
 *
 * 原典の初期データ要求「アバター設定済 / 未設定のユーザーが混在している」を満たす。
 * 素材は database/seeders/assets/avatars/ に置き、public disk へコピーしてから
 * users.avatar_url に公開 URL を書く(保存先と URL の形式は decisions #45 / StoreAvatarAction と同じ)。
 *
 * ⚠️ 素材を storage/app/public/ に直接コミットできないため、この Seeder が必要になる。
 *    同ディレクトリの .gitignore が `*` で全ファイルを無視するので、置いても配布されない。
 *
 * ⚠️ コピー先は `seed-{user id}.{ext}` とし、ユーザーごとに実体を分ける。
 *    素材 4 枚を複数ユーザーで共有すると、1 人がアバターを差し替え / 削除した時点で
 *    StoreAvatarAction / DestroyAvatarAction がその実ファイルを消し、
 *    同じファイルを指す他ユーザーが一斉にリンク切れになる。
 *
 * ⚠️ ユーザー ID は ULID で、`migrate:fresh --seed` のたびに採番し直される
 *    (UserSeeder は id を固定していない)。そのためファイル名も毎回変わり、前回分が残り続ける。
 *    実行のたびに孤児が積み上がらないよう、run() の冒頭で自分が作った `seed-*` だけを掃除する
 *    (利用者がアップロードしたファイルは ULID 名なので巻き込まない)。
 *
 * 保存先と公開 URL の組み立ては HasAvatarStorage を使い、本番のアップロードと同じ知識を共有する
 * (ディレクトリ名を変えたときに Seeder だけ取り残されないようにするため。decisions #117)。
 */
final class AvatarSeeder extends Seeder
{
    use HasAvatarStorage;

    /** 素材を置いたディレクトリ(database/seeders/assets/avatars/)。 */
    private const ASSET_DIRECTORY = 'seeders/assets/avatars';

    /** シードが作ったファイルの接頭辞。利用者のアップロード(ULID 名)と区別するために付ける。 */
    private const SEED_PREFIX = 'seed-';

    /** UserSeeder が作る固定アカウント。demo 割り当ての対象外にする。 */
    private const FIXED_ACCOUNT_EMAILS = [
        'admin@certify-lms.test',
        'coach@certify-lms.test',
        'coach2@certify-lms.test',
        'student@certify-lms.test',
        'student-noquota@certify-lms.test',
    ];

    public function run(): void
    {
        $sources = $this->assetFiles();

        if ($sources === []) {
            return;
        }

        $this->purgePreviousSeedFiles();

        // 固定アカウントのうち「設定済み」にする 3 人。残り(コーチ花子 / 面談残数なしの受講生)は
        // 未設定のまま残し、イニシャル表示との見比べができるようにする。
        $fixed = [
            'admin@certify-lms.test',
            'coach@certify-lms.test',
            'student@certify-lms.test',
        ];

        // 並び順を保ったまま引く(whereIn は順序を保証しないため 1 件ずつ引く)
        $targets = array_values(array_filter(array_map(
            fn (string $email): ?User => User::query()->firstWhere('email', $email),
            $fixed,
        )));

        // demo 受講生(Factory 生成の受講生)の一部にも割り当てる。
        // 一覧画面で設定済みと未設定が並ぶようにするため、全員には付けず 1 人おきにする。
        // 退会済み(deleted_at あり)は既定スコープで除外される。招待中(invited)は対象に含める——
        // 一覧画面には並ぶので、アイコンの有無を見比べる相手になる。
        // ログインできるかどうか(AuthenticateUserUsing)ではなく、一覧に出るかどうかで判断している。
        $demo = User::query()
            ->where('role', UserRole::Student->value)
            ->whereNotIn('email', self::FIXED_ACCOUNT_EMAILS)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->values()
            ->filter(fn (User $user, int $index): bool => $index % 2 === 0)
            ->all();

        foreach (array_merge($targets, $demo) as $index => $user) {
            $this->assign($user, $sources[$index % count($sources)]);
        }
    }

    /**
     * 1 ユーザーに 1 ファイルを与える。素材は使い回すが、コピー先はユーザーごとに分ける
     * (共有すると 1 人の差し替え / 削除で他ユーザーがリンク切れになる)。
     */
    private function assign(User $user, string $source): void
    {
        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $path = $this->avatarDirectory().'/'.self::SEED_PREFIX.$user->id.'.'.$ext;

        // public disk は 'throw' => false(config/filesystems.php:53)なので、失敗しても false が返るだけ。
        // 戻り値を見ないと「実ファイルの無い URL」を配ってしまい、証跡の撮影で無駄な調査が生まれる
        if (Storage::disk('public')->put($path, (string) file_get_contents($source)) === false) {
            throw new RuntimeException("アバター素材のコピーに失敗しました: {$path}");
        }

        $user->update(['avatar_url' => $this->avatarPublicUrl($path)]);
    }

    /**
     * 前回のシードが作ったファイルを消す。ユーザー ID が毎回変わるため、掃除しないと積み上がる。
     * 対象は接頭辞が `seed-` のものだけで、利用者がアップロードした ULID 名のファイルは残す。
     *
     * DB 側の参照も同時に外す。migrate:fresh を挟まず db:seed だけを再実行したとき、
     * 今回の対象から外れたユーザーに「実ファイルの無い URL」が残るのを防ぐ。
     */
    private function purgePreviousSeedFiles(): void
    {
        User::query()
            ->where('avatar_url', 'like', $this->avatarPublicUrl($this->avatarDirectory()).'/'.self::SEED_PREFIX.'%')
            ->update(['avatar_url' => null]);

        $disk = Storage::disk('public');
        $directory = $this->avatarDirectory();

        $stale = array_filter(
            $disk->files($directory),
            fn (string $file): bool => str_starts_with(basename($file), self::SEED_PREFIX),
        );

        if ($stale !== []) {
            $disk->delete($stale);
        }
    }

    /**
     * 素材ファイルの絶対パス一覧を返す。
     *
     * @return array<int, string>
     */
    private function assetFiles(): array
    {
        $assetDir = database_path(self::ASSET_DIRECTORY);

        if (! is_dir($assetDir)) {
            return [];
        }

        // png 以外の素材を足したときに黙って無視されないよう、扱える形式をすべて拾う
        // (StoreAvatarRequest が許す形式と揃える)
        $files = glob($assetDir.'/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
        sort($files);

        return $files;
    }
}
