<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 面談パック一覧(GET /admin/meeting-packs)を検証する。
 *
 * 原典「一覧表示(パック名のキーワード検索 + 状態フィルタ + ページネーション)」に対応。
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    /** 管理者は一覧を開ける */
    public function test_admin_can_view_list(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        MeetingPack::factory()->count(3)->create();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index'));

        // Assert: 支給 Blade が要求する変数名は $plans($meetingPacks ではない)
        $response->assertOk();
        $response->assertViewIs('meeting-pack.management.index');
        $response->assertViewHas('plans');
    }

    /** SKU 名で部分一致の検索ができる */
    public function test_keyword_filters_by_name(): void
    {
        // Arrange: 名前の一部だけが共通する2件を作る
        $admin = User::factory()->admin()->create();
        MeetingPack::factory()->create(['name' => '5 回パック']);
        MeetingPack::factory()->create(['name' => 'お試しプラン']);

        // Act: 「パック」で検索する
        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index', ['keyword' => 'パック']));

        // Assert: 一致した1件だけが出る
        $response->assertOk();
        $response->assertSee('5 回パック');
        $response->assertDontSee('お試しプラン');
    }

    /** 状態で絞り込める */
    public function test_status_filter_narrows_results(): void
    {
        // Arrange: 3状態を1件ずつ用意する
        $admin = User::factory()->admin()->create();
        MeetingPack::factory()->draft()->create(['name' => '下書きの品']);
        MeetingPack::factory()->published()->create(['name' => '販売中の品']);
        MeetingPack::factory()->archived()->create(['name' => '終売の品']);

        // Act
        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index', ['status' => 'published']));

        // Assert: 公開中の1件だけが残る
        $response->assertOk();
        $response->assertSee('販売中の品');
        $response->assertDontSee('下書きの品');
        $response->assertDontSee('終売の品');
    }

    /** 存在しない状態を指定すると入力エラーとして差し戻される */
    public function test_invalid_status_is_rejected(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act: URL を手で書き換えた場合を再現する
        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index', ['status' => 'not_a_status']));

        // Assert: 500 でも 403 でもなく、入力エラーとして扱われる
        $response->assertSessionHasErrors('status');
    }

    /** 1ページは20件で、21件目は次のページに回る */
    public function test_list_is_paginated_by_20(): void
    {
        // Arrange: 21件作る。並び順を昇順に振って、どれが2ページ目に回るか特定できるようにする
        $admin = User::factory()->admin()->create();
        foreach (range(1, 21) as $i) {
            MeetingPack::factory()->create([
                'name' => "パック{$i}",
                'sort_order' => $i,
            ]);
        }

        // Act
        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index'));

        // Assert: 1ページ目には20件が乗り、全体では21件と数えられている
        $response->assertOk();
        $plans = $response->viewData('plans');
        $this->assertCount(20, $plans);
        $this->assertSame(21, $plans->total());
    }

    /** 並び順の小さいものから表示される */
    public function test_list_is_sorted_by_sort_order(): void
    {
        // Arrange: わざと作成順と並び順を逆にする。作成順に出ていたら検知できる
        $admin = User::factory()->admin()->create();
        MeetingPack::factory()->create(['name' => '後ろに出る品', 'sort_order' => 9]);
        MeetingPack::factory()->create(['name' => '先に出る品', 'sort_order' => 1]);

        // Act
        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index'));

        // Assert: 並び順の昇順
        $names = $response->viewData('plans')->pluck('name')->all();
        $this->assertSame(['先に出る品', '後ろに出る品'], $names);
    }

    /** 検索語が長すぎると入力エラーになる */
    public function test_keyword_length_is_limited(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act: 検索欄の maxlength は 100。URL を手で書き換えればそれ以上も送れる
        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index', ['keyword' => str_repeat('あ', 101)]));

        // Assert
        $response->assertSessionHasErrors('keyword');

        // Act & Assert: 上限ちょうど(100文字)は通る。max の書き間違いを検知するため
        $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index', ['keyword' => str_repeat('あ', 100)]))
            ->assertOk();
    }

    /** 受講生とコーチは一覧を開けない */
    public function test_student_and_coach_cannot_view_list(): void
    {
        foreach ([User::factory()->student(), User::factory()->coach()] as $factory) {
            $this->actingAs($factory->create())
                ->get(route('admin.meeting-packs.index'))
                ->assertForbidden();
        }
    }

    /**
     * 一覧に購入数が載る(decisions #124)。
     *
     * ⚠️ 支給 Blade は `$plan->payments_count ?? 0`(meeting-pack/management/index.blade.php:135)と書いているため、
     *    withCount を付け忘れても**例外にならず 0 件と表示され続ける**。
     *    画面が落ちない分いちばん気付きにくいので、ここで機械的に止める。
     */
    public function test_index_includes_payment_count(): void
    {
        // Arrange: 購入が 2 件あるパックと、1 件も無いパック
        $admin = User::factory()->admin()->create();
        $sold = MeetingPack::factory()->published()->create();
        $unsold = MeetingPack::factory()->published()->create();
        Payment::factory()->count(2)->succeeded()->forPack($sold)->create();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index'));

        // Assert
        $response->assertOk();
        $response->assertViewHas('plans', function ($plans) use ($sold, $unsold) {
            $soldRow = $plans->firstWhere('id', $sold->id);
            $unsoldRow = $plans->firstWhere('id', $unsold->id);

            return $soldRow?->payments_count === 2 && $unsoldRow?->payments_count === 0;
        });
    }

    /** 購入数には未完了(pending / failed)を含めない(decisions #232) */
    public function test_payment_count_excludes_unsettled_payments(): void
    {
        // Arrange: 完了 1 件 + 未完了 2 件
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->published()->create();
        Payment::factory()->succeeded()->forPack($plan)->create();
        Payment::factory()->pending()->forPack($plan)->create();
        Payment::factory()->failed()->forPack($plan)->create();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index'));

        // Assert: 数えるのは完了の 1 件だけ
        $response->assertOk();
        $response->assertViewHas('plans', fn ($plans) => $plans->firstWhere('id', $plan->id)?->payments_count === 1);
    }
}
