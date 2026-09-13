<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講プラン一覧(GET /admin/plans)を検証する。
 *
 * 原典「一覧: プラン名のキーワード検索 + 状態フィルタ + ページネーション、各行に契約中の受講者数を表示」に対応。
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    /** 管理者は一覧を開ける */
    public function test_admin_can_view_list(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        Plan::factory()->count(3)->create();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.plans.index'));

        // Assert: 支給 Blade が読む変数名は $plans。別名にすると画面が落ちる
        $response->assertOk();
        $response->assertViewIs('plan.management.index');
        $response->assertViewHas('plans');
    }

    /** プラン名で部分一致の検索ができる */
    public function test_keyword_filters_by_name(): void
    {
        // Arrange: 名前の一部だけが共通する2件を作る
        $admin = User::factory()->admin()->create();
        Plan::factory()->create(['name' => '3 ヶ月プラン 12 回']);
        Plan::factory()->create(['name' => 'お試しコース']);

        // Act: 「プラン」で検索する
        $response = $this->actingAs($admin)
            ->get(route('admin.plans.index', ['keyword' => 'プラン']));

        // Assert: 一致した1件だけが出る
        $response->assertOk();
        $response->assertSee('3 ヶ月プラン 12 回');
        $response->assertDontSee('お試しコース');
    }

    /** 状態で絞り込める */
    public function test_status_filter_narrows_results(): void
    {
        // Arrange: 3状態を1件ずつ用意する
        $admin = User::factory()->admin()->create();
        Plan::factory()->draft()->create(['name' => '下書きの品']);
        Plan::factory()->published()->create(['name' => '公開中の品']);
        Plan::factory()->archived()->create(['name' => 'アーカイブの品']);

        // Act
        $response = $this->actingAs($admin)
            ->get(route('admin.plans.index', ['status' => 'published']));

        // Assert
        $response->assertOk();
        $response->assertSee('公開中の品');
        $response->assertDontSee('下書きの品');
        $response->assertDontSee('アーカイブの品');
    }

    /** 受講生とコーチは一覧を開けない */
    public function test_student_and_coach_cannot_view_list(): void
    {
        // Arrange & Act & Assert: 他の9エンドポイントと同じ形で、一覧にも拒否の検証を置く
        foreach ([User::factory()->student(), User::factory()->coach()] as $factory) {
            $user = $factory->create();

            $this->actingAs($user)
                ->get(route('admin.plans.index'))
                ->assertForbidden();
        }
    }

    /**
     * 2 ページ目へ進んでも絞り込みが消えない(withQueryString)。
     * キーワードと状態を同時に指定した場合も両方効く。
     */
    public function test_filters_survive_pagination_and_combine(): void
    {
        // Arrange: 「対象」を含む公開中を 21 件、紛れ込ませる下書きと別名を 1 件ずつ作る
        $admin = User::factory()->admin()->create();
        Plan::factory()->count(21)->published()->create(['name' => '対象プラン']);
        Plan::factory()->draft()->create(['name' => '対象プラン(下書き)']);
        Plan::factory()->published()->create(['name' => '別のプラン']);

        // Act: 2 ページ目をキーワード + 状態つきで開く
        $response = $this->actingAs($admin)->get(route('admin.plans.index', [
            'keyword' => '対象',
            'status' => 'published',
            'page' => 2,
        ]));

        // Assert: 21 件中の 1 件だけが 2 ページ目に残る。下書きと別名は除外されたまま
        $response->assertOk();
        $plans = $response->viewData('plans');
        $this->assertSame(21, $plans->total());
        $this->assertCount(1, $plans->items());

        // Assert: ページ送りのリンクに keyword と status が載っている(消えるとフィルタが外れる)
        $this->assertStringContainsString('keyword='.urlencode('対象'), $plans->previousPageUrl());
        $this->assertStringContainsString('status=published', $plans->previousPageUrl());
    }

    /**
     * Enum に無い status は弾く。
     * URL を手で打ち替えられても Action まで届かないことを固定する
     * (届くと PlanStatus::from() が例外を投げて 500 になる)。
     */
    public function test_unknown_status_is_rejected(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)
            ->get(route('admin.plans.index', ['status' => 'foo']));

        // Assert: FormRequest が弾いて直前の画面へ戻す
        $response->assertRedirect();
        $response->assertSessionHasErrors('status');
    }

    /** 検索語は 100 文字まで(支給 Blade の maxlength="100" に合わせている) */
    public function test_too_long_keyword_is_rejected(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act: 101 文字を送る
        $response = $this->actingAs($admin)
            ->get(route('admin.plans.index', ['keyword' => str_repeat('あ', 101)]));

        // Assert
        $response->assertRedirect();
        $response->assertSessionHasErrors('keyword');

        // Assert: 100 文字ちょうどは通る。ここが無いと max:99 の書き間違いに気づけない
        $this->actingAs($admin)
            ->get(route('admin.plans.index', ['keyword' => str_repeat('あ', 100)]))
            ->assertOk();
    }

    /**
     * 「受講者数」は契約中(受講中 + 招待中)だけを数える。decisions #126。
     *
     * ⚠️ 支給 seeder は招待中・退会済みに plan_id を持たせないため、この組み合わせは
     * Factory でしか作れない。開発データを目で見ても検出できない。
     */
    public function test_user_count_includes_only_contracted_users(): void
    {
        // Arrange: 同じプランに4種類の状態のユーザーをぶら下げる
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();

        User::factory()->student()->inProgress()->withPlan($plan)->create();  // 数える
        User::factory()->student()->invited()->withPlan($plan)->create();     // 数える
        User::factory()->student()->graduated()->withPlan($plan)->create();   // 数えない
        // 退会済み: status だけでなく論理削除もされる。plan_id は消えない
        User::factory()->student()->withdrawn()->withPlan($plan)->create()->delete();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.plans.index'));

        // Assert: 4人ぶら下がっているが、数えるのは受講中 + 招待中の2人だけ
        $response->assertOk();
        $this->assertSame(2, $response->viewData('plans')->first()->users_count);
    }

    /** 並び順は sort_order の昇順(Plan::scopeOrdered) */
    public function test_list_is_ordered_by_sort_order(): void
    {
        // Arrange: わざと並びを崩して作る
        $admin = User::factory()->admin()->create();
        Plan::factory()->create(['name' => '3 番目', 'sort_order' => 30]);
        Plan::factory()->create(['name' => '1 番目', 'sort_order' => 10]);
        Plan::factory()->create(['name' => '2 番目', 'sort_order' => 20]);

        // Act
        $response = $this->actingAs($admin)->get(route('admin.plans.index'));

        // Assert: sort_order の小さい順に並ぶ
        $response->assertOk();
        $this->assertSame(
            ['1 番目', '2 番目', '3 番目'],
            $response->viewData('plans')->pluck('name')->all(),
        );
    }

    /** 1 ページは 20 件(decisions #64 / #94。既存の一覧処理と同じ) */
    public function test_list_is_paginated_by_20(): void
    {
        // Arrange: 1 ページに収まらない件数を作る
        $admin = User::factory()->admin()->create();
        Plan::factory()->count(21)->create();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.plans.index'));

        // Assert: 1 ページ目は 20 件、総数は 21 件
        $response->assertOk();
        $plans = $response->viewData('plans');
        $this->assertCount(20, $plans->items());
        $this->assertSame(21, $plans->total());
    }
}
