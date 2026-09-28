<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 機能全体の ON / OFF スイッチを検証する(S-A-02)。
 *
 * 原典 非機能要件「機能全体を無効化するスイッチ(OFF にすると関連 UI・画面ごと利用できなくなる)」。
 *
 * ⚠️ スイッチは**ルートを登録するかどうか**で効かせている(routes/web.php)。
 *    ルートはアプリの起動時に 1 度だけ読まれるので、テストの中で config() を差し替えても遅い。
 *    そのため、**アプリを作る前に**環境変数を差し替える(setUp で parent::setUp() より先)。
 *
 * ⭐ 画面側に @if を 1 つも書いていないのに UI が消えることを、ここで確かめる ——
 *    サイドバー項目は resources/views/components/nav/item.blade.php:14 の Route::has() が、
 *    フローティングウィジェットは resources/views/layouts/app.blade.php:60 の
 *    config('ai-chat.enabled') 判定が、それぞれ自動的に消す。
 */
class FeatureSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // phpunit.xml は AI_CHAT_ENABLED=true を force で固定している。
        // ここだけ false に倒してからアプリを起動する。
        putenv('AI_CHAT_ENABLED=false');
        $_ENV['AI_CHAT_ENABLED'] = 'false';
        $_SERVER['AI_CHAT_ENABLED'] = 'false';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // 他のテストに漏らさない
        putenv('AI_CHAT_ENABLED=true');
        $_ENV['AI_CHAT_ENABLED'] = 'true';
        $_SERVER['AI_CHAT_ENABLED'] = 'true';

        parent::tearDown();
    }

    public function test_the_switch_is_off_in_this_test(): void
    {
        // Assert: 前提の確認。ここが true だと以降のテストが無意味になる
        $this->assertFalse((bool) config('ai-chat.enabled'));
    }

    public function test_no_route_is_registered(): void
    {
        // Assert: 6 本すべて存在しない
        $this->assertFalse(Route::has('ai-chat.index'));
        $this->assertFalse(Route::has('ai-chat.conversations.store'));
        $this->assertFalse(Route::has('ai-chat.conversations.show'));
        $this->assertFalse(Route::has('ai-chat.conversations.update'));
        $this->assertFalse(Route::has('ai-chat.conversations.destroy'));
        $this->assertFalse(Route::has('ai-chat.conversations.messages.store'));
    }

    public function test_the_url_returns_404(): void
    {
        // Arrange: 画面から消えていても URL を直接叩かれる可能性がある
        //          (CLAUDE.md「画面から消したものは URL でも塞ぐ」)
        $student = User::factory()->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($student)->get('/ai-chat');

        // Assert: ルートごと無いので 404
        $response->assertNotFound();
    }

    public function test_the_widget_and_the_sidebar_item_disappear(): void
    {
        // Arrange: 学習中の受講生がふだんの画面を開く
        $student = User::factory()->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($student)->get(route('dashboard.index'));

        // Assert: 画面は壊れず、AI 相談の UI だけが消える
        $response->assertOk();
        // フローティングウィジェット本体の data 属性
        $response->assertDontSee('data-ai-chat-widget', false);
        // サイドバーの項目
        $response->assertDontSee('AI 相談');
    }
}
