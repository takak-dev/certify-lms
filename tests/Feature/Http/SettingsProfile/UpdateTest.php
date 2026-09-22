<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * プロフィール更新 (`PATCH settings.profile.update`) を検証する Feature テスト。
 *
 * 検証の柱は 3 つ。
 *  1. 氏名 / 自己紹介を本人が更新できる
 *  2. email は画面で readonly。送られても更新されない(原典スコープ外「メール変更は管理者経由のみ」)
 *  3. meeting_url はコーチだけ。受講生 / 管理者が送っても無視される(原典「入力欄が現れず、操作もできない」)
 */
class UpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_redirected(): void
    {
        // Arrange: ログインしていない状態

        // Act
        $response = $this->patch(route('settings.profile.update'), ['name' => '名無し']);

        // Assert
        $response->assertRedirect(route('login'));
    }

    public function test_student_can_update_name_and_bio(): void
    {
        // Arrange: 自己紹介が空の受講生
        $student = User::factory()->student()->inProgress()->create([
            'name' => '変更前の氏名',
            'bio' => null,
        ]);

        // Act
        $response = $this->actingAs($student)->patch(route('settings.profile.update'), [
            'name' => '変更後の氏名',
            'bio' => "簿記2級を目指しています。\n週末に学習しています。",
        ]);

        // Assert: 一覧ではなく同じ設定画面へ戻り、_共通ルール.md の文言でフラッシュする
        $response->assertRedirect(route('settings.profile.edit'));
        $response->assertSessionHas('success', 'プロフィールを更新しました。');

        $student->refresh();
        $this->assertSame('変更後の氏名', $student->name);
        // 改行をそのまま保持すること(表示側が whitespace-pre-wrap で描画する前提)
        $this->assertSame("簿記2級を目指しています。\n週末に学習しています。", $student->bio);
    }

    public function test_email_is_not_updated_even_if_it_is_submitted(): void
    {
        // Arrange: メールアドレスが既知のユーザー
        $student = User::factory()->student()->inProgress()->create([
            'email' => 'original@example.com',
        ]);

        // Act: 画面には readonly の欄しか無いが、フォームを改ざんして送られた場合を再現する
        $response = $this->actingAs($student)->patch(route('settings.profile.update'), [
            'name' => '氏名',
            'email' => 'attacker@example.com',
        ]);

        // Assert: 422 で弾くのではなく「無かったこと」にする(rules に無い = validated() に乗らない)
        $response->assertRedirect(route('settings.profile.edit'));
        $this->assertSame('original@example.com', $student->refresh()->email);
    }

    /**
     * 支給の config/fortify.php は Features::updateProfileInformation() を有効にしており、
     * PUT /user/profile-information が認証のみで登録されていた。この受け口は email を必須で受け
     * forceFill で保存する一方、User は MustVerifyEmail を実装していないため確認メールも飛ばない。
     * email はログイン ID(config/fortify.php:50)なので、書き換えられると管理者が本人を特定できなくなる。
     *
     * S-B-06 で該当機能を features から外した。設定ファイルは戻されやすいので、ここで機械的に固定する。
     */
    public function test_fortify_email_change_endpoint_is_not_registered(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create([
            'email' => 'original@example.com',
        ]);

        // Act & Assert: ルート自体が存在しないこと
        $this->assertFalse(
            Route::has('user-profile-information.update'),
            'Fortify のプロフィール情報更新ルートが復活している(config/fortify.php の features を確認すること)',
        );

        // Act: 直接パスを叩いても届かないこと
        $response = $this->actingAs($student)->put('/user/profile-information', [
            'name' => '氏名',
            'email' => 'attacker@example.com',
        ]);

        // Assert
        $response->assertNotFound();
        $this->assertSame('original@example.com', $student->refresh()->email);
    }

    /**
     * 修了済(graduated)の受講生も更新できること。
     * 閲覧・パスワード変更・アバターは 4 パターンで固定済みだが、更新だけ抜けていた。
     */
    public function test_graduated_student_can_update_profile(): void
    {
        // Arrange
        $student = User::factory()->student()->graduated()->create(['name' => '修了前の氏名']);

        // Act
        $response = $this->actingAs($student)->patch(route('settings.profile.update'), [
            'name' => '修了後の氏名',
        ]);

        // Assert
        $response->assertRedirect(route('settings.profile.edit'));
        $this->assertSame('修了後の氏名', $student->refresh()->name);
    }

    /**
     * 権限昇格が起きないこと。
     * role / status は User::$fillable に含まれる(User.php:35-36)ので、
     * FormRequest の rules と UpdateProfileAction の Arr::only の二重で弾く。
     *
     * @param array<string, string> $payload
     */
    #[DataProvider('escalationAttempts')]
    public function test_privileged_columns_cannot_be_changed(array $payload, string $column, string $expected): void
    {
        // Arrange: 受講中の受講生
        $student = User::factory()->student()->inProgress()->create();

        // Act: 画面に無い項目を混ぜて送る
        $response = $this->actingAs($student)->patch(
            route('settings.profile.update'),
            array_merge(['name' => '氏名'], $payload),
        );

        // Assert: 422 で弾くのではなく無視する。値は元のまま
        $response->assertRedirect(route('settings.profile.edit'));
        $this->assertSame($expected, $student->refresh()->{$column}->value);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function escalationAttempts(): array
    {
        return [
            'ロールを管理者に変える' => [['role' => 'admin'], 'role', 'student'],
            '状態を修了済に変える' => [['status' => 'graduated'], 'status', 'in_progress'],
        ];
    }

    public function test_coach_can_update_meeting_url(): void
    {
        // Arrange: 固定面談 URL が未設定のコーチ
        $coach = User::factory()->coach()->inProgress()->create(['meeting_url' => null]);

        // Act
        $response = $this->actingAs($coach)->patch(route('settings.profile.update'), [
            'name' => 'コーチ氏名',
            'meeting_url' => 'https://meet.google.com/abc-defg-hij',
        ]);

        // Assert
        $response->assertRedirect(route('settings.profile.edit'));
        $this->assertSame('https://meet.google.com/abc-defg-hij', $coach->refresh()->meeting_url);
    }

    public function test_coach_can_clear_meeting_url(): void
    {
        // Arrange: 既に URL を持つコーチ。設定画面では任意項目なので空にできる
        // (オンボーディングでは必須だが、meeting/show.blade.php:72-76 に未設定コーチ向けの
        //  警告カードがあり、空になりうる前提で画面が作られている)
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.google.com/old-url',
        ]);

        // Act
        $this->actingAs($coach)->patch(route('settings.profile.update'), [
            'name' => 'コーチ氏名',
            'meeting_url' => '',
        ]);

        // Assert
        $this->assertNull($coach->refresh()->meeting_url);
    }

    /**
     * 受講生と管理者には画面に入力欄が出ない。サーバー側でも受け付けないことを固定する。
     */
    #[DataProvider('nonCoachRoles')]
    public function test_non_coach_cannot_set_meeting_url(string $factoryState): void
    {
        // Arrange: コーチ以外のユーザー
        $user = User::factory()->{$factoryState}()->inProgress()->create(['meeting_url' => null]);

        // Act
        $response = $this->actingAs($user)->patch(route('settings.profile.update'), [
            'name' => '氏名',
            'meeting_url' => 'https://evil.example.com/room',
        ]);

        // Assert: email と同じく「無かったこと」にする
        $response->assertRedirect(route('settings.profile.edit'));
        $this->assertNull($user->refresh()->meeting_url);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonCoachRoles(): array
    {
        return [
            '受講生' => ['student'],
            '管理者' => ['admin'],
        ];
    }

    /**
     * 入力不備は元の画面へ戻し、その場で直せるようにする(原典「入力に不備があるときは、その場で内容を直せる」)。
     *
     * @param array<string, string> $payload
     */
    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected(array $payload, string $expectedErrorKey): void
    {
        // Arrange
        $coach = User::factory()->coach()->inProgress()->create(['name' => '元の氏名']);

        // Act: どの画面から送ったかを from() で指定し、戻り先の検証を可能にする
        $response = $this->actingAs($coach)
            ->from(route('settings.profile.edit'))
            ->patch(route('settings.profile.update'), $payload);

        // Assert: 直前の画面へ戻り、該当項目にエラーが立つ。DB は変わらない
        $response->assertRedirect(route('settings.profile.edit'));
        $response->assertSessionHasErrors($expectedErrorKey);
        $this->assertSame('元の氏名', $coach->refresh()->name);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidInputs(): array
    {
        return [
            '氏名が空' => [['name' => ''], 'name'],
            // Blade の maxlength="50" は表示側の制限でしかないので、サーバー側の境界も固定する
            '氏名が51文字' => [['name' => str_repeat('あ', 51)], 'name'],
            '自己紹介が1001文字' => [['name' => '氏名', 'bio' => str_repeat('い', 1001)], 'bio'],
            '固定面談URLが URL 形式でない' => [['name' => '氏名', 'meeting_url' => 'not-a-url'], 'meeting_url'],
            '固定面談URLが501文字' => [['name' => '氏名', 'meeting_url' => 'https://example.com/'.str_repeat('a', 482)], 'meeting_url'],
            // 素の url ルールは file: など 200 種類以上のスキームを許すため url:http,https で絞っている。
            // この値は予約時に meeting_url_snapshot へ写され、受講生の画面の <a href> に出る
            // (StoreAction::__invoke() / meeting/show.blade.php:61,65)
            '固定面談URLが http/https 以外' => [['name' => '氏名', 'meeting_url' => 'file:///etc/passwd'], 'meeting_url'],
        ];
    }
}
