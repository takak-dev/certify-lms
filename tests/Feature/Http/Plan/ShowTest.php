<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講プランの詳細と、フォームを開く画面(GET /admin/plans/{plan} / create / edit)を検証する。
 *
 * 原典「詳細: 基本情報 + 紐づく受講者一覧 + メタ情報(作成者 / 最終更新者 / 作成日時)を表示」に対応。
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
        $plan = Plan::factory()->draft()->create([
            'name' => '3 ヶ月プラン 12 回',
            'created_by_user_id' => $author->id,
            'updated_by_user_id' => $editor->id,
        ]);

        // Act
        $response = $this->actingAs($author)->get(route('admin.plans.show', $plan));

        // Assert: 支給 Blade が読む変数名は $plan(単数)
        $response->assertOk();
        $response->assertViewIs('plan.management.show');
        $response->assertViewHas('plan');
        $response->assertSee('3 ヶ月プラン 12 回');
        $response->assertSee('作成した管理者');
        $response->assertSee('更新した管理者');
    }

    /**
     * 受講者一覧も契約中(受講中 + 招待中)だけを載せる。decisions #126。
     *
     * 一覧の件数と詳細の一覧が同じ定義であることが要点。
     * 支給 Blade は数値カード(show.blade.php:115)と受講者一覧(:135)の両方で
     * 同じ $plan->users を読むので、ここを絞れば 2 箇所とも揃う。
     */
    public function test_user_list_includes_only_contracted_users(): void
    {
        // Arrange: 同じプランに4種類の状態のユーザーをぶら下げる
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();

        User::factory()->student()->inProgress()->withPlan($plan)->create(['name' => '受講中の人']);
        User::factory()->student()->invited()->withPlan($plan)->create(['name' => '招待中の人']);
        User::factory()->student()->graduated()->withPlan($plan)->create(['name' => '卒業した人']);
        // 退会済み: 実際の退会は status 更新 + 論理削除の2段(UserWithdrawalService)
        User::factory()->student()->withdrawn()->withPlan($plan)->create(['name' => '退会した人'])->delete();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.plans.show', $plan));

        // Assert: 画面に出るのは2人だけ
        $response->assertOk();
        $response->assertSee('受講中の人');
        $response->assertSee('招待中の人');
        $response->assertDontSee('卒業した人');
        $response->assertDontSee('退会した人');

        // 数値カードが読む $plan->users も同じ2人(Blade は ->count() で数える)
        $this->assertCount(2, $response->viewData('plan')->users);
    }

    /**
     * 画面が使うリレーションは Action が読み込んでおく。
     * 読み込み漏れがあっても Blade は遅延読み込みで描画できてしまい、
     * 受講者の行数だけ問い合わせが増える(N+1)ため、ここで固定する。
     */
    public function test_relations_are_eager_loaded(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.plans.show', $plan));

        // Assert
        $viewPlan = $response->viewData('plan');
        $this->assertTrue($viewPlan->relationLoaded('users'));
        $this->assertTrue($viewPlan->relationLoaded('createdBy'));
        $this->assertTrue($viewPlan->relationLoaded('updatedBy'));
    }

    /** 存在しない ID は 404 */
    public function test_missing_id_returns_404(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.plans.show', '01JXXXXXXXXXXXXXXXXXXXXXXX'))
            ->assertNotFound();
    }

    /** 管理者は新規作成フォームを開ける */
    public function test_admin_can_view_create_form(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.plans.create'));

        $response->assertOk();
        $response->assertViewIs('plan.management.create');
    }

    /** 管理者は編集フォームを開け、現在の値が初期表示される */
    public function test_admin_can_view_edit_form_with_current_values(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create(['name' => '3 ヶ月プラン 12 回']);

        // Act
        $response = $this->actingAs($admin)->get(route('admin.plans.edit', $plan));

        // Assert
        $response->assertOk();
        $response->assertViewIs('plan.management.edit');
        $response->assertViewHas('plan');
        $response->assertSee('3 ヶ月プラン 12 回');
    }

    /** 受講生とコーチは閲覧系3画面をどれも開けない */
    public function test_student_and_coach_cannot_view_any_screen(): void
    {
        $plan = Plan::factory()->draft()->create();

        $urls = [
            route('admin.plans.show', $plan),
            route('admin.plans.create'),
            route('admin.plans.edit', $plan),
        ];

        foreach ([User::factory()->student(), User::factory()->coach()] as $factory) {
            $user = $factory->create();
            foreach ($urls as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
        }
    }
}
