<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile\Avatar;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * アバター画像のアップロード (`POST settings.avatar.store`) の Feature テスト。
 *
 * 保存先は public disk の avatars/{ulid}.{ext}、users.avatar_url には公開 URL を入れる(decisions #45)。
 * Storage::fake('public') で実ファイル系の操作を差し替え、実際のディスクを汚さない。
 */
class StoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_redirected(): void
    {
        // Arrange
        Storage::fake('public');

        // Act
        $response = $this->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('icon.png'),
        ]);

        // Assert
        $response->assertRedirect(route('login'));
    }

    /**
     * 全ロールと修了済の受講生がアップロードできること。
     * 原典「アバター画像(全ロール)」「修了済もアバター更新は引き続き使いたい」に対応する。
     */
    #[DataProvider('usersWhoCanUpload')]
    public function test_user_can_upload_avatar(string $roleState, string $statusState): void
    {
        // Arrange
        Storage::fake('public');
        $user = User::factory()->{$roleState}()->{$statusState}()->create(['avatar_url' => null]);

        // Act
        $response = $this->actingAs($user)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('icon.png'),
        ]);

        // Assert
        $response->assertRedirect(route('settings.profile.edit'));
        $response->assertSessionHas('success', 'アイコン画像を更新しました。');

        // avatar_url は <img src> にそのまま入る公開 URL。ファイル名は ULID(26 文字)で採番される
        $user->refresh();
        $this->assertMatchesRegularExpression('#^/storage/avatars/[0-9A-Z]{26}\.png$#', $user->avatar_url);

        // 実ファイルが public disk に存在すること(URL から先頭の /storage/ を除いた位置)
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $user->avatar_url));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function usersWhoCanUpload(): array
    {
        return [
            '受講中の受講生' => ['student', 'inProgress'],
            'コーチ' => ['coach', 'inProgress'],
            '管理者' => ['admin', 'inProgress'],
            '修了済の受講生' => ['student', 'graduated'],
        ];
    }

    /**
     * 許可した 3 形式それぞれが保存できること。拡張子は送信ファイル名ではなく中身から決まるので
     * (decisions #113)、中身の形式ごとに保存名が変わることを固定する。
     * png だけを通していると StoreAvatarAction の match の jpg / webp のアームを壊しても気づけない。
     */
    #[DataProvider('acceptedImageFormats')]
    public function test_each_accepted_format_is_stored_with_the_expected_extension(string $sourceName, string $expectedExtension): void
    {
        // Arrange
        Storage::fake('public');
        $user = User::factory()->student()->inProgress()->create(['avatar_url' => null]);

        // Act: fake の画像はファイル名の拡張子から中身の形式が決まる
        $response = $this->actingAs($user)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image($sourceName),
        ]);

        // Assert
        $response->assertRedirect(route('settings.profile.edit'));

        $avatarUrl = $user->refresh()->avatar_url;
        $this->assertStringEndsWith('.'.$expectedExtension, $avatarUrl);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $avatarUrl));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function acceptedImageFormats(): array
    {
        return [
            // jpeg は jpg に正規化される(guessExtension() の戻り値。実測で確認済み)
            'PNG' => ['icon.png', 'png'],
            'JPEG' => ['icon.jpeg', 'jpg'],
            'WebP' => ['icon.webp', 'webp'],
        ];
    }

    public function test_replacing_avatar_removes_the_previous_file(): void
    {
        // Arrange: 1 枚目をアップロード済みの状態を作る
        Storage::fake('public');
        $user = User::factory()->student()->inProgress()->create(['avatar_url' => null]);

        $this->actingAs($user)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('first.png'),
        ]);
        $firstPath = str_replace('/storage/', '', $user->refresh()->avatar_url);

        // Act: 2 枚目で差し替える
        $this->actingAs($user)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('second.png'),
        ]);
        $secondPath = str_replace('/storage/', '', $user->refresh()->avatar_url);

        // Assert: URL が変わり、旧ファイルは残らない(ULID 採番なので必ず別名になる)
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertExists($secondPath);
        Storage::disk('public')->assertMissing($firstPath);
    }

    /**
     * ⭐ セキュリティ回帰テスト。
     *
     * 中身が PNG で名前が icon.html のファイルは、FormRequest の mimes を通過する
     * (mimes は中身を見る / Laravel が名前で弾くのは .php 系だけ)。
     * 送信者が付けた名前をそのまま拡張子に使うと /storage/avatars/{ulid}.html が
     * このアプリ自身のドメインで HTML として配信されてしまうため、
     * StoreAvatarAction は guessExtension()(中身から推測した拡張子)で決めている。
     * mimes ルールが照合に使うのと同じ関数なので、検証を通ったファイルが保存側で弾かれる窓が無い。
     *
     * ⚠️ UploadedFile::fake() は getMimeType() を「ファイル名」から返す実装で
     *    (Illuminate\Http\Testing\File::getMimeType())、guessExtension() はその戻り値を使うため、
     *    fake では中身と名前が食い違う状態を作れない＝この攻撃を再現できない。
     *    実ファイルを指す本物の UploadedFile を組み立てて、中身から判定させる。
     */
    public function test_extension_comes_from_file_contents_not_from_the_submitted_filename(): void
    {
        // Arrange: 中身は本物の PNG、名前だけ .html にしたファイルを用意する
        Storage::fake('public');
        $user = User::factory()->student()->inProgress()->create(['avatar_url' => null]);

        $png = UploadedFile::fake()->image('seed.png');   // 実体の PNG を生成させる
        $disguised = new UploadedFile(
            $png->getPathname(),
            'icon.html',    // ← 送信者が名乗るファイル名
            null,           // MIME は宣言しない(サーバー側に中身から判定させる)
            null,
            true,           // test モード: is_uploaded_file() の検査を飛ばす
        );

        // Act
        $response = $this->actingAs($user)->post(route('settings.avatar.store'), [
            'avatar' => $disguised,
        ]);

        // Assert: 検証は通過し(400 系にならない)、保存名は .png になる
        $response->assertRedirect(route('settings.profile.edit'));

        $avatarUrl = $user->refresh()->avatar_url;
        $this->assertStringEndsWith('.png', $avatarUrl);
        $this->assertStringNotContainsString('.html', $avatarUrl);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $avatarUrl));
    }

    #[DataProvider('invalidUploads')]
    public function test_invalid_upload_is_rejected(callable $makeFile): void
    {
        // Arrange
        Storage::fake('public');
        $user = User::factory()->student()->inProgress()->create(['avatar_url' => null]);

        // Act
        $response = $this->actingAs($user)
            ->from(route('settings.profile.edit'))
            ->post(route('settings.avatar.store'), array_filter(['avatar' => $makeFile()]));

        // Assert: 元の画面へ戻り、avatar 欄にエラーが立つ。DB もディスクも変わらない
        $response->assertRedirect(route('settings.profile.edit'));
        $response->assertSessionHasErrors('avatar');
        $this->assertNull($user->refresh()->avatar_url);
        $this->assertEmpty(Storage::disk('public')->allFiles('avatars'));
    }

    /**
     * @return array<string, array{0: callable}>
     */
    public static function invalidUploads(): array
    {
        return [
            // hint「2MB 以内」= max:2048(KB)。境界の 1KB 超で落ちること
            '2MB を超える画像' => [fn () => UploadedFile::fake()->image('big.png')->size(2049)],
            '画像ではないファイル' => [fn () => UploadedFile::fake()->create('memo.txt', 10, 'text/plain')],
            // 許可リストの境界。GIF は画像だが対象外で、StoreAvatarAction の match まで届かず
            // FormRequest で止まること(届くと 500 のエラーページになり「時間をおいて再度お試しください」
            // という直しようのない案内が出てしまう)
            '対象外の画像形式(GIF)' => [fn () => UploadedFile::fake()->image('icon.gif')],
            // SVG はスクリプトを埋め込める形式。中身から判定されて弾かれること
            '対象外の画像形式(SVG)' => [fn () => UploadedFile::fake()->create('icon.svg', 1, 'image/svg+xml')],
            'ファイル未選択' => [fn () => null],
        ];
    }
}
