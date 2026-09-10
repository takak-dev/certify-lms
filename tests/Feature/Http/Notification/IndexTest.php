<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 通知一覧（GET /notifications）の検証。
 *
 * ここで固定するのは 3 つ。
 * 1. 自分宛の通知しか見えないこと（原典の「他人宛の通知は閲覧・既読化できない」）
 * 2. ロールや利用状態で画面が閉じないこと。修了者も管理者も開ける
 *    （EnsureActiveLearning の PHPDoc が「通知一覧は引き続き利用可能」と名指しで除外している）
 * 3. タブ（全件 / 未読のみ）とページネーションが効くこと
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 通知を 1 件作るヘルパ。
     *
     * DatabaseNotification にはファクトリが無い（Laravel が vendor で持つモデルのため）ので、
     * リレーション経由で直接 create する。$read で既読 / 未読を作り分ける。
     */
    private function makeNotification(User $for, bool $read = false, string $title = 'テスト通知'): void
    {
        $for->notifications()->create([
            // 主キーは Laravel の採番に合わせて UUID
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            'data' => [
                'notification_type' => 'qa_reply_received',
                'title' => $title,
                'message' => '本文のプレビュー',
                'url' => '/dashboard',
            ],
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_student_sees_only_own_notifications(): void
    {
        // Arrange: 自分に 1 件、他人に 1 件。他人宛が漏れないことを見る
        $me = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $this->makeNotification($me, title: '自分宛の通知');
        $this->makeNotification($other, title: '他人宛の通知');

        // Act
        $response = $this->actingAs($me)->get(route('notifications.index'));

        // Assert
        $response->assertOk();
        $response->assertSee('自分宛の通知');
        $response->assertDontSee('他人宛の通知');
    }

    public function test_graduated_student_can_open_notifications(): void
    {
        // Arrange: 修了者は配信対象から外れる（decisions #34）が、
        //          既に受け取った通知の閲覧まで塞ぐと過去の通知に到達できなくなる
        $graduated = User::factory()->student()->graduated()->create();
        $this->makeNotification($graduated, title: '修了前に届いた通知');

        // Act
        $response = $this->actingAs($graduated)->get(route('notifications.index'));

        // Assert: active-learning を付けていないので 403 にならない
        $response->assertOk();
        $response->assertSee('修了前に届いた通知');
    }

    public function test_coach_and_admin_can_open_notifications(): void
    {
        // Arrange: サイドバーは 3 ロールすべてに通知項目を持つ。
        //          管理者宛の通知は発火しない（スコープ外）が、画面自体は開ける
        foreach ([User::factory()->coach()->create(), User::factory()->admin()->create()] as $user) {
            // Act & Assert
            $this->actingAs($user)->get(route('notifications.index'))->assertOk();
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        // Act: 未ログインでアクセスする
        $response = $this->get(route('notifications.index'));

        // Assert: auth ミドルウェアがログイン画面へ送る
        $response->assertRedirect(route('login'));
    }

    public function test_unread_tab_shows_only_unread(): void
    {
        // Arrange: 未読 1 件・既読 1 件
        $me = User::factory()->student()->inProgress()->create();
        $this->makeNotification($me, read: false, title: 'まだ読んでいない通知');
        $this->makeNotification($me, read: true, title: 'もう読んだ通知');

        // Act: x-tabs が付ける ?tab=unread（tabs.blade.php:9 の既定パラメータ名）
        $response = $this->actingAs($me)->get(route('notifications.index', ['tab' => 'unread']));

        // Assert
        $response->assertOk();
        $response->assertSee('まだ読んでいない通知');
        $response->assertDontSee('もう読んだ通知');
    }

    public function test_invalid_tab_is_rejected(): void
    {
        // Arrange
        $me = User::factory()->student()->inProgress()->create();

        // Act: 画面が用意していない値を URL に直接入れる
        $response = $this->actingAs($me)->get(route('notifications.index', ['tab' => 'archived']));

        // Assert: IndexRequest の Rule::in で弾く（422 ではなく 302 + エラーバッグ）
        $response->assertSessionHasErrors('tab');
    }

    public function test_list_is_paginated_by_twenty(): void
    {
        // Arrange: 1 ページ 20 件（decisions #64 と同じく既存の一覧 Action に合わせた）
        $me = User::factory()->student()->inProgress()->create();
        for ($i = 0; $i < 21; $i++) {
            $this->makeNotification($me, title: "通知{$i}");
        }

        // Act
        $response = $this->actingAs($me)->get(route('notifications.index'));

        // Assert: 21 件目が 2 ページ目に送られる = ページャが出ている
        $response->assertOk();
        $response->assertSee('page=2');
    }

    public function test_newest_notification_comes_first(): void
    {
        // Arrange: 作成時刻をずらして 2 件
        $me = User::factory()->student()->inProgress()->create();
        $this->makeNotification($me, title: '古い通知');
        $me->notifications()->latest()->first()->update(['created_at' => now()->subDay()]);
        $this->makeNotification($me, title: '新しい通知');

        // Act
        $response = $this->actingAs($me)->get(route('notifications.index'));

        // Assert: notifications() が ->latest() を持つため新着が上に来る
        //         （HasDatabaseNotifications.php:14）
        $response->assertSeeInOrder(['新しい通知', '古い通知']);
    }
}
