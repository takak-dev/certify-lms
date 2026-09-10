<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 既読化（POST /notifications/{notification}/read）と
 * 一括既読（POST /notifications/read-all）の検証。
 *
 * ここで固定するのは 3 つ。
 * 1. 他人の通知を既読にできないこと（原典「他人宛の通知は閲覧・既読化できない」）
 * 2. 既読化したあと data の `url` が指す業務画面へ送ること（decisions #42）
 * 3. `url` に外部サイトを入れられても、そこへは飛ばさないこと（オープンリダイレクト対策）
 */
class MarkAsReadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 通知を 1 件作って返すヘルパ。
     *
     * $url に null を渡すと `url` キー自体を持たない通知になる（運営お知らせを想定）。
     */
    private function makeNotification(User $for, ?string $url = '/dashboard', bool $read = false): DatabaseNotification
    {
        return $for->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            'data' => array_filter([
                'notification_type' => 'qa_reply_received',
                'title' => 'テスト通知',
                'message' => '本文のプレビュー',
                'url' => $url,
            ]),
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_owner_can_mark_as_read_and_is_redirected_to_the_target_screen(): void
    {
        // Arrange
        $me = User::factory()->student()->inProgress()->create();
        $notification = $this->makeNotification($me, url: '/dashboard');

        // Act
        $response = $this->actingAs($me)->post(route('notifications.markAsRead', $notification));

        // Assert: 既読になり、data の url が指す画面へ送られる
        $response->assertRedirect('/dashboard');
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_notification_without_url_falls_back_to_the_list(): void
    {
        // Arrange: 遷移先を持たない通知（運営お知らせ = S-B-08 が作る想定）
        $me = User::factory()->student()->inProgress()->create();
        $notification = $this->makeNotification($me, url: null);

        // Act
        $response = $this->actingAs($me)->post(route('notifications.markAsRead', $notification));

        // Assert: 行き先が無いので一覧へ戻す。既読化そのものは走る
        $response->assertRedirect(route('notifications.index'));
        $this->assertNotNull($notification->fresh()->read_at);
    }

    /**
     * 外部サイトへ飛ばす URL を弾く。
     *
     * `//evil.example.com` は「プロトコルだけ今のページから引き継ぐ」書き方で、
     * 見た目は相対パスなのにブラウザは外部ホストとして解釈する。
     * `/` で始まるかだけを見る実装だとすり抜けるため、ここで固定しておく。
     */
    public function test_external_url_is_not_followed(): void
    {
        // Arrange
        $me = User::factory()->student()->inProgress()->create();

        foreach (['https://evil.example.com/steal', '//evil.example.com/steal'] as $dangerous) {
            $notification = $this->makeNotification($me, url: $dangerous);

            // Act
            $response = $this->actingAs($me)->post(route('notifications.markAsRead', $notification));

            // Assert: 外部へは飛ばさず一覧へ戻す
            $response->assertRedirect(route('notifications.index'));
        }
    }

    public function test_other_user_cannot_mark_as_read(): void
    {
        // Arrange: 他人宛の通知の ID を URL に直接打ち込む
        $me = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $notification = $this->makeNotification($other);

        // Act
        $response = $this->actingAs($me)->post(route('notifications.markAsRead', $notification));

        // Assert: NotificationPolicy::markAsRead が弾く。未読のまま残る
        $response->assertForbidden();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_marking_twice_keeps_the_first_read_time(): void
    {
        // Arrange: すでに既読の通知
        $me = User::factory()->student()->inProgress()->create();
        $notification = $this->makeNotification($me, read: true);
        $firstReadAt = $notification->fresh()->read_at;

        // Act: 同じ行をもう一度クリックする
        $this->actingAs($me)->post(route('notifications.markAsRead', $notification));

        // Assert: Laravel の markAsRead() は read_at が null のときだけ書き込むため上書きされない
        $this->assertEquals($firstReadAt, $notification->fresh()->read_at);
    }

    public function test_mark_all_marks_only_own_notifications(): void
    {
        // Arrange: 自分に未読 2 件、他人に未読 1 件
        $me = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $mine = [$this->makeNotification($me), $this->makeNotification($me)];
        $theirs = $this->makeNotification($other);

        // Act
        $response = $this->actingAs($me)->post(route('notifications.markAllAsRead'));

        // Assert
        $response->assertRedirect(route('notifications.index'));
        $response->assertSessionHas('success');
        $this->assertSame(0, $me->fresh()->unreadNotifications()->count());
        // 他人の未読には触らない
        $this->assertNull($theirs->fresh()->read_at);
        foreach ($mine as $notification) {
            $this->assertNotNull($notification->fresh()->read_at);
        }
    }

    public function test_guest_cannot_mark_as_read(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $notification = $this->makeNotification($owner);

        // Act: 未ログインで叩く
        $response = $this->post(route('notifications.markAsRead', $notification));

        // Assert: auth ミドルウェアがログイン画面へ送り、通知は未読のまま
        $response->assertRedirect(route('login'));
        $this->assertNull($notification->fresh()->read_at);
    }
}
