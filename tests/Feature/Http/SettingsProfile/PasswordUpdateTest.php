<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * パスワード変更 (`PUT settings.password.update`) を検証する Feature テスト。
 *
 * 検証の柱は 3 つ。
 *  1. 現在のパスワードを確認したうえで変更できる(原典「現在のパスワードを確認したうえで」)
 *  2. 失敗時のエラーが `updatePassword` バッグに入る(支給 Blade tab-password.blade.php:7-8 が読む名前)
 *  3. 変更後もログアウトしない(支給 UpdateUserPassword にセッション操作が無い / decisions #44)
 *
 * ⚠️ ファクトリの初期パスワードは 'password'(UserFactory.php:30)。
 */
class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_redirected(): void
    {
        // Arrange: ログインしていない状態

        // Act
        $response = $this->put(route('settings.password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        // Assert
        $response->assertRedirect(route('login'));
    }

    /**
     * 全ロールと修了済の受講生が変更できること。
     * 原典「管理者も同じ自己管理ニーズを持つ」「修了済もパスワード変更は引き続き使いたい」に対応する。
     */
    #[DataProvider('usersWhoCanChangePassword')]
    public function test_user_can_change_password(string $roleState, string $statusState): void
    {
        // Arrange
        $user = User::factory()->{$roleState}()->{$statusState}()->create();

        // Act
        $response = $this->actingAs($user)->put(route('settings.password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        // Assert: パスワードタブを開いたまま戻り、新しいパスワードで照合できる
        $response->assertRedirect(route('settings.profile.edit', ['tab' => 'password']));
        $response->assertSessionHas('success', 'パスワードを変更しました。');
        $this->assertTrue(Hash::check('new-password-123', $user->refresh()->password));

        // 変更してもログアウトしない(セッションを切らない仕様)
        $this->assertAuthenticatedAs($user);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function usersWhoCanChangePassword(): array
    {
        return [
            '受講中の受講生' => ['student', 'inProgress'],
            'コーチ' => ['coach', 'inProgress'],
            '管理者' => ['admin', 'inProgress'],
            '修了済の受講生' => ['student', 'graduated'],
        ];
    }

    /**
     * 現在のパスワード誤りのメッセージが日本語で出ること。
     *
     * 支給の UpdateUserPassword は英語の文字列をキーにして __() に渡すため(同 :34)、
     * 翻訳が無いと日本語の画面に英語の文が 1 つだけ混ざる。lang/ja.json で訳している。
     * 翻訳ファイルは消されても他のテストが落ちないので、ここで固定する。
     */
    public function test_current_password_mismatch_message_is_localized(): void
    {
        // Arrange
        $user = User::factory()->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($user)
            ->from(route('settings.profile.edit', ['tab' => 'password']))
            ->put(route('settings.password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ]);

        // Assert: updatePassword バッグに日本語のメッセージが入る
        $response->assertSessionHasErrors(
            ['current_password' => '現在のパスワードが正しくありません。'],
            null,
            'updatePassword',
        );
    }

    /**
     * 項目名も日本語で出ること。支給の UpdateUserPassword は attributes() を持たないため、
     * lang/ja/validation.php の 'attributes' が空だと `password と確認用の…` と英語のまま出る。
     */
    public function test_password_attribute_name_is_localized(): void
    {
        // Arrange
        $user = User::factory()->student()->inProgress()->create();

        // Act: 確認用を一致させずに送る
        $response = $this->actingAs($user)
            ->from(route('settings.profile.edit', ['tab' => 'password']))
            ->put(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'different-password',
            ]);

        // Assert
        $response->assertSessionHasErrors(
            ['password' => 'パスワード と確認用の入力が一致しません。'],
            null,
            'updatePassword',
        );
    }

    /**
     * 失敗パターン。いずれも元のパスワードが残り、エラーは updatePassword バッグに入る。
     *
     * @param array<string, string> $payload
     */
    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected(array $payload, string $expectedErrorKey): void
    {
        // Arrange
        $user = User::factory()->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($user)
            ->from(route('settings.profile.edit', ['tab' => 'password']))
            ->put(route('settings.password.update'), $payload);

        // Assert: 直前の画面へ戻り、名前付きバッグにエラーが立つ
        $response->assertRedirect(route('settings.profile.edit', ['tab' => 'password']));
        $response->assertSessionHasErrors([$expectedErrorKey], null, 'updatePassword');

        // 元のパスワードのままであること
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidInputs(): array
    {
        return [
            '現在のパスワードが違う' => [[
                'current_password' => 'wrong-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ], 'current_password'],

            '現在のパスワードが未入力' => [[
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ], 'current_password'],

            // B-B-13 で同じ抜けをオンボーディング側で修正した。ここでも confirmed が効くことを固定する
            '確認用が一致しない' => [[
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'different-password',
            ], 'password'],

            // decisions #44: Password::default() = 8 文字以上。境界の 7 文字で落ちること
            '新パスワードが7文字' => [[
                'current_password' => 'password',
                'password' => 'short12',
                'password_confirmation' => 'short12',
            ], 'password'],
        ];
    }
}
