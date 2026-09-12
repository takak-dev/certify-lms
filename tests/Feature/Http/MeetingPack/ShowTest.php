<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 面談パックの閲覧系3画面(詳細 / 新規作成フォーム / 編集フォーム)を検証する。
 *
 * 原典「詳細表示(基本情報 + 直近の購入履歴 + メタ情報)」に対応。
 * 購入履歴は App\Models\Payment(S-A-03 で作る)が無いと表示されないため、
 * ここでは「無くても画面が落ちないこと」を確認する。
 */
class ShowTest extends TestCase
{
    use RefreshDatabase;

    /** 管理者は詳細を開け、メタ情報に作成者と最終更新者が出る */
    public function test_admin_can_view_detail_with_meta(): void
    {
        // Arrange: 作成者と最終更新者を別人にして、両方が表示されることを確かめる
        $author = User::factory()->admin()->create(['name' => '作成した管理者']);
        $editor = User::factory()->admin()->create(['name' => '更新した管理者']);
        $plan = MeetingPack::factory()->draft()->create([
            'name' => '5 回パック',
            'created_by_user_id' => $author->id,
            'updated_by_user_id' => $editor->id,
        ]);

        // Act
        $response = $this->actingAs($author)->get(route('admin.meeting-packs.show', $plan));

        // Assert: 支給 Blade が要求する変数名は $plan(単数)
        $response->assertOk();
        $response->assertViewIs('meeting-pack.management.show');
        $response->assertViewHas('plan');
        $response->assertSee('5 回パック');
        $response->assertSee('作成した管理者');
        $response->assertSee('更新した管理者');
    }

    /** 購入の仕組みが未実装でも詳細画面は落ちない */
    public function test_detail_renders_without_payment_model(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->published()->create();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.show', $plan));

        // Assert: 購入履歴セクションは「まだありません」と出る(支給 Blade が class_exists でガードしている)
        $response->assertOk();
        $response->assertSee('この SKU の購入はまだありません。');
    }

    /** 存在しない ID は 404 */
    public function test_missing_id_returns_404(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.meeting-packs.show', '01JXXXXXXXXXXXXXXXXXXXXXXX'))
            ->assertNotFound();
    }

    /** 管理者は新規作成フォームを開ける */
    public function test_admin_can_view_create_form(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.create'));

        $response->assertOk();
        $response->assertViewIs('meeting-pack.management.create');
    }

    /** 管理者は編集フォームを開け、現在の値が初期表示される */
    public function test_admin_can_view_edit_form_with_current_values(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create(['name' => '5 回パック']);

        // Act
        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.edit', $plan));

        // Assert
        $response->assertOk();
        $response->assertViewIs('meeting-pack.management.edit');
        $response->assertViewHas('plan');
        $response->assertSee('5 回パック');
    }

    /** 受講生とコーチは閲覧系3画面をどれも開けない */
    public function test_student_and_coach_cannot_view_any_screen(): void
    {
        $plan = MeetingPack::factory()->draft()->create();

        $urls = [
            route('admin.meeting-packs.show', $plan),
            route('admin.meeting-packs.create'),
            route('admin.meeting-packs.edit', $plan),
        ];

        foreach ([User::factory()->student(), User::factory()->coach()] as $factory) {
            $user = $factory->create();
            foreach ($urls as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
        }
    }
}
