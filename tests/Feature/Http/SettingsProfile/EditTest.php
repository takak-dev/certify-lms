<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * プロフィール設定画面 (`settings.profile.edit`) の描画を検証する Feature テスト。
 *
 * このチケットの要件は「全ロールが共通の設定画面を使う」「修了済(graduated)の受講生も使える」。
 * ルートに role: / active-learning を付けていないことを、4 パターンの実アクセスで固定する。
 */
class EditTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_redirected(): void
    {
        // Arrange: ログインしていない状態

        // Act
        $response = $this->get(route('settings.profile.edit'));

        // Assert: auth ミドルウェアがログイン画面へ飛ばす
        $response->assertRedirect(route('login'));
    }

    /**
     * 受講中の受講生 / コーチ / 管理者 / 修了済の受講生 の 4 パターンすべてが 200 で開けること。
     *
     * ここが崩れる典型が「既存の settings グループ(role:student + active-learning)に相乗りする」実装で、
     * その場合はコーチ・管理者・修了済がすべて 403 になる。
     */
    #[DataProvider('usersWhoCanOpenTheScreen')]
    public function test_every_role_including_graduated_student_can_open_the_screen(string $factoryState, string $statusState): void
    {
        // Arrange: ロールと状態の組み合わせでユーザーを作る
        $user = User::factory()->{$factoryState}()->{$statusState}()->create();

        // Act
        $response = $this->actingAs($user)->get(route('settings.profile.edit'));

        // Assert: 画面が開き、Blade が期待する $user が渡っている
        $response->assertOk();
        $response->assertViewIs('settings.profile');
        $this->assertTrue($response->viewData('user')->is($user));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function usersWhoCanOpenTheScreen(): array
    {
        return [
            '受講中の受講生' => ['student', 'inProgress'],
            'コーチ' => ['coach', 'inProgress'],
            '管理者' => ['admin', 'inProgress'],
            '修了済の受講生' => ['student', 'graduated'],
        ];
    }

    /**
     * 原典の要件「本人の氏名 / メール / 自己紹介 / アイコン画像 / ロール / アカウント状態を確認できる」。
     *
     * 画面は支給済みだが、要件そのものはテストで固定しておく
     * (支給 Blade が壊れても assertOk だけでは気づけないため)。
     */
    public function test_screen_shows_the_required_profile_fields(): void
    {
        // Arrange: 表示を確認しやすい固定値を入れる
        $user = User::factory()->coach()->inProgress()->create([
            'name' => '確認用コーチ',
            'email' => 'checking@example.com',
            'bio' => '自己紹介の確認用テキスト',
            'avatar_url' => '/storage/avatars/dummy.png',
        ]);

        // Act
        $response = $this->actingAs($user)->get(route('settings.profile.edit'));

        // Assert: 氏名 / メール / 自己紹介 / ロール / アカウント状態 / アイコン画像
        $response->assertSee('確認用コーチ');
        $response->assertSee('checking@example.com');
        $response->assertSee('自己紹介の確認用テキスト');
        $response->assertSee($user->role->label());
        $response->assertSee($user->status->label());
        $response->assertSee('/storage/avatars/dummy.png');
    }

    /**
     * 原典「受講生 / 管理者には固定面談 URL の入力欄が現れず、操作もできない」の前半(画面側)。
     * 後半(サーバー側)は UpdateTest::test_non_coach_cannot_set_meeting_url が担当する。
     */
    #[DataProvider('meetingUrlFieldVisibility')]
    public function test_meeting_url_field_is_shown_only_to_coach(string $roleState, bool $shouldSee): void
    {
        // Arrange
        $user = User::factory()->{$roleState}()->inProgress()->create();

        // Act
        $response = $this->actingAs($user)->get(route('settings.profile.edit'));

        // Assert: 入力欄は name="meeting_url" で描画される(tab-profile.blade.php:49-59)
        $shouldSee
            ? $response->assertSee('name="meeting_url"', false)
            : $response->assertDontSee('name="meeting_url"', false);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function meetingUrlFieldVisibility(): array
    {
        return [
            'コーチには出る' => ['coach', true],
            '受講生には出ない' => ['student', false],
            '管理者には出ない' => ['admin', false],
        ];
    }

    /**
     * タブ切替は ?tab= のクエリで行い、不正値はプロフィールへ丸める(profile.blade.php:10-18)。
     * JS を使わない作りなので、サーバーが返す HTML だけで検証できる。
     */
    #[DataProvider('tabQueries')]
    public function test_tab_is_selected_by_query_parameter(?string $tab, string $expectedMarker, string $absentMarker): void
    {
        // Arrange
        $user = User::factory()->student()->inProgress()->create();

        // Act
        $url = $tab === null
            ? route('settings.profile.edit')
            : route('settings.profile.edit', ['tab' => $tab]);
        $response = $this->actingAs($user)->get($url);

        // Assert: 選ばれた側が出て、もう一方は出ないこと。
        // 片側だけ見ると「両方を同時に描画する」実装に変えても緑のままになる
        $response->assertOk();
        $response->assertSee($expectedMarker, false);
        $response->assertDontSee($absentMarker, false);
    }

    /**
     * @return array<string, array{0: ?string, 1: string, 2: string}>
     */
    public static function tabQueries(): array
    {
        // 目印: プロフィールタブ = アイコン画像の入力欄 / パスワードタブ = 現在のパスワード欄
        $profile = 'name="avatar"';
        $password = 'name="current_password"';

        return [
            'クエリ無し' => [null, $profile, $password],
            'プロフィール' => ['profile', $profile, $password],
            'パスワード' => ['password', $password, $profile],
            // 不正値はプロフィールに丸める
            '不正値' => ['unknown', $profile, $password],
        ];
    }
}
