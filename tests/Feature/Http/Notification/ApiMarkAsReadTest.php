<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 既読化 API(POST /api/v1/notifications/{notification}/read)と
 * 一括既読 API(POST /api/v1/notifications/read-all)の検証(S-A-05)。
 *
 * ここで固定するのは 4 つ。
 * 1. 他人の通知 ID を指定すると 403 で、しかも既読にもならないこと
 *    (原典「他者の通知 ID を指定すると拒否される」)
 * 2. 既読化はリダイレクトではなく、遷移先の URL を JSON で返すこと
 *    (fetch はリダイレクトを画面に反映しないため。JS が location.href で移動する)
 * 3. 遷移先の決め方は web 版と同じであること(外部 URL を弾く / url の無い通知は詳細ページへ)
 * 4. 一括既読は自分の未読だけを既読にし、処理後の未読件数を返すこと
 *
 * web 版の MarkAsReadTest と同じ Action を通るため、Action の細かい挙動(2 回目のクリックで時刻を
 * 上書きしない等)はあちらで固定済み。ここでは「API として正しい返し方をするか」に絞る。
 */
class ApiMarkAsReadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 通知を 1 件作って返すヘルパ(web 版 MarkAsReadTest と同じ作り方)。
     *
     * $url に null を渡すと `url` キー自体を持たない通知になる(運営お知らせを想定)。
     */
    private function makeNotification(User $for, ?string $url = '/dashboard', bool $read = false): DatabaseNotification
    {
        return $for->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            // array_filter で null の url を取り除き、キーそのものが無い通知を作る
            'data' => array_filter([
                'notification_type' => 'qa_reply_received',
                'title' => 'テスト通知',
                'message' => '本文のプレビュー',
                'url' => $url,
            ]),
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_owner_marks_as_read_and_gets_the_target_url_as_json(): void
    {
        // Arrange
        $me = User::factory()->student()->inProgress()->create();
        $notification = $this->makeNotification($me, url: '/dashboard');

        // Act
        $response = $this->actingAs($me)->postJson(route('api.v1.notifications.markAsRead', $notification));

        // Assert: 302 のリダイレクトではなく 200 の JSON で遷移先を返し、既読になっている
        $response->assertOk();
        $response->assertExactJson(['url' => '/dashboard']);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_notification_without_url_returns_the_detail_page(): void
    {
        // Arrange: 遷移先の業務画面を持たない通知(運営お知らせ)
        $me = User::factory()->student()->inProgress()->create();
        $notification = $this->makeNotification($me, url: null);

        // Act
        $response = $this->actingAs($me)->postJson(route('api.v1.notifications.markAsRead', $notification));

        // Assert: web 版と同じく通知詳細ページを返す(decisions #42 / #83)
        $response->assertExactJson(['url' => route('notifications.show', $notification)]);
    }

    public function test_external_url_is_not_returned(): void
    {
        // Arrange: 外部サイトへ飛ばす URL を持つ通知。
        // JS はこの値をそのまま location.href に入れるので、ここで弾けていないと外部へ飛ぶ
        $me = User::factory()->student()->inProgress()->create();

        // `/\evil…` と、タブを挟んだ `/<TAB>/evil…` はブラウザが `//evil…` と読み替える(2026-09-29 実測)
        $dangerousUrls = [
            'https://evil.example.com/steal',
            '//evil.example.com/steal',
            '/\\evil.example.com/steal',
            "/\t/evil.example.com/steal",
        ];

        foreach ($dangerousUrls as $dangerous) {
            $notification = $this->makeNotification($me, url: $dangerous);

            // Act
            $response = $this->actingAs($me)->postJson(route('api.v1.notifications.markAsRead', $notification));

            // Assert: 外部の URL は返さず、詳細ページに置き換わる
            $response->assertExactJson(['url' => route('notifications.show', $notification)]);
        }
    }

    public function test_other_users_notification_is_forbidden_and_stays_unread(): void
    {
        // Arrange: 他人宛の通知の ID を直接指定する
        $me = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $notification = $this->makeNotification($other);

        // Act
        $response = $this->actingAs($me)->postJson(route('api.v1.notifications.markAsRead', $notification));

        // Assert: 403。しかも既読にもなっていない。
        // 認可を Action より後ろに書くと「403 なのに既読になる」ので、両方を確かめる
        $response->assertForbidden();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_guest_cannot_mark_as_read(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $notification = $this->makeNotification($owner);

        // Act: ログインせずに叩く
        $response = $this->postJson(route('api.v1.notifications.markAsRead', $notification));

        // Assert: 401 で、既読にもならない
        $response->assertUnauthorized();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_all_marks_only_own_and_returns_remaining_count(): void
    {
        // Arrange: 自分に未読 2 件、他人に未読 1 件
        $me = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $mine = [$this->makeNotification($me), $this->makeNotification($me)];
        $theirs = $this->makeNotification($other);

        // Act
        $response = $this->actingAs($me)->postJson(route('api.v1.notifications.markAllAsRead'));

        // Assert: 処理後の自分の未読件数(0)を JSON で返す。リダイレクトはしない
        $response->assertOk();
        $response->assertExactJson(['unread_count' => 0]);
        foreach ($mine as $notification) {
            $this->assertNotNull($notification->fresh()->read_at);
        }
        // 他人の未読には触らない
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_guest_cannot_mark_all(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $notification = $this->makeNotification($owner);

        // Act
        $response = $this->postJson(route('api.v1.notifications.markAllAsRead'));

        // Assert
        $response->assertUnauthorized();
        $this->assertNull($notification->fresh()->read_at);
    }
}
