<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile\Avatar;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * アバター画像の削除 (`DELETE settings.avatar.destroy`) の Feature テスト。
 *
 * 原典「アイコン画像を削除すると、アイコン未設定の表示に戻る」に対応する。
 * users.avatar_url を null に戻し、実ファイルも消えることを検証する。
 */
class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_redirected(): void
    {
        // Arrange: ログインしていない状態

        // Act
        $response = $this->delete(route('settings.avatar.destroy'));

        // Assert
        $response->assertRedirect(route('login'));
    }

    public function test_user_can_delete_avatar(): void
    {
        // Arrange: アップロード済みの状態を作る
        Storage::fake('public');
        $user = User::factory()->student()->inProgress()->create(['avatar_url' => null]);

        $this->actingAs($user)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('icon.png'),
        ]);
        $path = str_replace('/storage/', '', $user->refresh()->avatar_url);

        // Act
        $response = $this->actingAs($user)->delete(route('settings.avatar.destroy'));

        // Assert: 未設定の表示に戻り、実ファイルも消える
        $response->assertRedirect(route('settings.profile.edit'));
        $response->assertSessionHas('success', 'アイコン画像を削除しました。');
        $this->assertNull($user->refresh()->avatar_url);
        Storage::disk('public')->assertMissing($path);

        // 画面側も未設定の表示に戻ること(<x-avatar> は src が無いとイニシャルを出す。avatar.blade.php:32-40)
        $this->actingAs($user)
            ->get(route('settings.profile.edit'))
            ->assertDontSee('/storage/avatars/', false);
    }

    public function test_deleting_when_no_avatar_is_set_does_not_fail(): void
    {
        // Arrange: 削除フォームは avatar_url がある時だけ表示されるが(tab-profile.blade.php:105)、
        //          直接リクエストされた場合に 500 にならないことを固定する
        Storage::fake('public');
        $user = User::factory()->student()->inProgress()->create(['avatar_url' => null]);

        // Act
        $response = $this->actingAs($user)->delete(route('settings.avatar.destroy'));

        // Assert
        $response->assertRedirect(route('settings.profile.edit'));
        $this->assertNull($user->refresh()->avatar_url);
    }
}
