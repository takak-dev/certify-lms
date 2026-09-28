<?php

declare(strict_types=1);

namespace Tests\Feature\View;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * トップバーの通知ポップオーバーをロールで出し分けていることの検証(S-A-05)。
 *
 * - 受講生・コーチ: ベルとポップオーバーのパネルが両方あり、JS が使う一覧 API の URL と、
 *   フルページへの導線(原典「フル画面へ遷移するボタンあり」= 支給のフッターリンク)も描かれている
 * - 管理者: ベルは残るが、パネルは描かれない(decisions #214 / #259)
 *
 * 管理者の「押しても何も起きない」は、パネルが無ければ JS(resources/js/notification/popover.js)が
 * 何もしない、という作りに乗っている。パネルが管理者に出てしまうと、JS が開いてしまうので、
 * 画面の HTML の段階で固定しておく。
 *
 * 開く画面は通知一覧(/notifications)。ロールや受講状態で閉じない画面なので(routes/web.php の「全ロール共通」)、
 * 3 ロールを同じ条件で比べられる。
 */
class NotificationPopoverVisibilityTest extends TestCase
{
    use RefreshDatabase;

    // ベルのボタンとパネル本体の目印(topbar.blade.php / notification-popover.blade.php)
    private const BELL = 'data-notification-popover-trigger';

    private const PANEL = 'data-notification-popover-panel';

    // フッターの「すべての通知を見る」(notification-popover.blade.php:60-66)
    private const FOOTER_LINK = 'data-notification-popover-footer-link';

    /**
     * 受講生・コーチに共通の確認: ベル・パネル・フッターリンク・一覧 API の URL が揃っていること。
     *
     * URL は属性名と値をまとめて探す。route() で埋め込んだ値が変わった(ルート名を変えた)ときにここで落ちる
     */
    private function assertPopoverIsFullyRendered(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertSee(self::BELL, false);
        $response->assertSee(self::PANEL, false);
        $response->assertSee(self::FOOTER_LINK, false);
        $response->assertSee('data-notification-popover-index-url="'.route('api.v1.notifications.index').'"', false);
    }

    public function test_student_gets_bell_and_popover_panel(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($student)->get(route('notifications.index'));

        // Assert: 属性名は HTML の中の文字そのものなので、エスケープせずに探す(ヘルパの中で第 2 引数 false)
        $this->assertPopoverIsFullyRendered($response);
    }

    public function test_coach_gets_bell_and_popover_panel(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();

        // Act
        $response = $this->actingAs($coach)->get(route('notifications.index'));

        // Assert: 受講生と同じものが揃う(同じ通知基盤を共有する。原典ユーザーストーリー)
        $this->assertPopoverIsFullyRendered($response);
    }

    public function test_admin_keeps_bell_but_gets_no_popover_panel(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->get(route('notifications.index'));

        // Assert: ベルは支給のまま残す(#214「隠さない」)。パネルと、JS が使う API の URL だけが無い
        // (管理者の HTML にポップオーバー用のものを残さない。decisions #259 / #262 ④)
        $response->assertOk();
        $response->assertSee(self::BELL, false);
        $response->assertDontSee(self::PANEL, false);
        $response->assertDontSee('data-notification-popover-index-url', false);
    }
}
