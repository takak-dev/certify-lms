<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 通知詳細ページ（GET /notifications/{notification}）の検証。S-B-08 で追加した画面。
 *
 * 原典の HTTP 表の認可欄は「認証ユーザー（自分宛の通知のみ）」。ロールで絞らない。
 * 守りたいのは 2 つ——①他人の通知を URL 直打ちで開けないこと
 * ②遷移先の業務画面を持たない通知（運営お知らせ）の全文がここで読めること。
 */
class ShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipient_can_read_the_full_body(): void
    {
        // Arrange: 本文が一覧のプレビューに収まらない長さのお知らせ
        $me = User::factory()->student()->inProgress()->create();
        $notification = $this->makeAnnouncementNotificationFor($me, [
            'title' => '年末年始の運営休止について',
            'body' => "12 月 29 日から 1 月 3 日まで、運営窓口を休止します。\n\n期間中も教材の閲覧はご利用いただけます。",
        ]);

        // Act
        $response = $this->actingAs($me)->get(route('notifications.show', $notification));

        // Assert: タイトル・本文・お知らせであることを示すラベルが出る
        $response->assertOk();
        $response->assertSee('年末年始の運営休止について');
        $response->assertSee('期間中も教材の閲覧はご利用いただけます。');
        $response->assertSee('運営からのお知らせ');
    }

    /**
     * 他人宛の通知は開けない。通知 ID を URL に直接打ち込む経路を塞ぐ。
     * B-B-09（未登録資格の教材を URL 直打ちで開けた）と同じ型の穴を作らないため。
     */
    public function test_other_users_cannot_open_it(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $stranger = User::factory()->student()->inProgress()->create();
        $notification = $this->makeAnnouncementNotificationFor($owner);

        // Act
        $response = $this->actingAs($stranger)->get(route('notifications.show', $notification));

        // Assert
        $response->assertForbidden();
    }

    /**
     * 管理者でも他人宛の通知は開けない。
     *
     * NotificationPolicy はロールを見ないので現状 403 になるが、将来 Gate::before で
     * 管理者を素通しする変更が入ると、この画面だけ全通知が見えるようになる。
     * そのときテストが 1 本も落ちないのは危ないので、ここで固定する。
     */
    public function test_admins_cannot_open_someone_elses_notification(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $notification = $this->makeAnnouncementNotificationFor($owner);

        // Act & Assert
        $this->actingAs($admin)->get(route('notifications.show', $notification))->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $notification = $this->makeAnnouncementNotificationFor($owner);

        // Act & Assert
        $this->get(route('notifications.show', $notification))->assertRedirect(route('login'));
    }

    /**
     * 修了者も自分宛の通知は開ける。
     *
     * 通知一覧に role: も active-learning も付けていない（decisions #85）ので、
     * 詳細ページだけ狭くならないようにする。修了前に受け取った通知を読み返せる必要がある。
     */
    public function test_graduated_users_can_still_open_their_own_notifications(): void
    {
        // Arrange: 受講中に通知を受け取り、その後に修了した受講生
        $user = User::factory()->student()->inProgress()->create();
        $notification = $this->makeAnnouncementNotificationFor($user);
        $user->update(['status' => UserStatus::Graduated->value]);

        // Act
        $response = $this->actingAs($user->fresh())->get(route('notifications.show', $notification));

        // Assert
        $response->assertOk();
    }

    /**
     * 宛先本人あてのお知らせ通知を 1 件作る。
     *
     * notify() を使わないのは、配信対象の判定（DeliversToActiveUsersOnly）を通すと
     * このテストの主題ではない条件に結果が左右されるため。ここで見たいのは通知詳細の認可と表示。
     * データの形は本番と同じ toDatabase() を通して作る。
     *
     * @param array<string, string> $attributes
     */
    private function makeAnnouncementNotificationFor(User $user, array $attributes = []): DatabaseNotification
    {
        $announcement = Announcement::factory()->create($attributes);
        $notification = new AdminAnnouncementNotification($announcement);

        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $notification::class,
            'data' => $notification->toDatabase($user),
            'read_at' => null,
        ]);
    }
}
