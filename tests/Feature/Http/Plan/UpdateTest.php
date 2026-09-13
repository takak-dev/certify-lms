<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講プランの更新(PUT /admin/plans/{plan})を検証する。
 *
 * 原典「編集: 基本情報(プラン名 / 説明 / 受講期間 / 初期付与面談回数 / 並び順)を更新。
 * 状態は本フォームでは変更しない」に対応。
 *
 * ⚠️ HTTP メソッドは PUT。面談パック(S-B-02)は PATCH なので取り違えに注意。
 * 支給 edit.blade.php:26 が @method('PUT') を出している。
 */
class UpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 送信データのひな形。
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => '3 ヶ月プラン 12 回(改定)',
            'description' => '説明も書き換える。',
            'duration_days' => 100,
            'default_meeting_quota' => 14,
            'sort_order' => 7,
        ], $override);
    }

    /** 管理者は基本情報を更新でき、最終更新者だけが差し替わる */
    public function test_admin_can_update_basic_fields(): void
    {
        // Arrange: 作成者と操作者を別人にして、created_by が変わらないことを確かめる
        $author = User::factory()->admin()->create();
        $editor = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create([
            'name' => '3 ヶ月プラン 12 回',
            'duration_days' => 90,
            'default_meeting_quota' => 12,
            'created_by_user_id' => $author->id,
            'updated_by_user_id' => $author->id,
        ]);

        // Act
        $response = $this->actingAs($editor)
            ->put(route('admin.plans.update', $plan), $this->payload());

        // Assert: 詳細画面へ戻り、成功メッセージが出る
        $response->assertRedirect(route('admin.plans.show', $plan));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => '3 ヶ月プラン 12 回(改定)',
            'duration_days' => 100,
            'default_meeting_quota' => 14,
            'sort_order' => 7,
            // 作った人は変わらない。最終更新者だけ操作した人になる
            'created_by_user_id' => $author->id,
            'updated_by_user_id' => $editor->id,
        ]);
    }

    /**
     * 編集フォームでは状態を変えられない。
     *
     * 支給 edit.blade.php には status の入力欄が無く、rules() にも入れていないので、
     * 送っても validated() に乗らず Action にも届かない。
     */
    public function test_status_is_not_changed_by_update(): void
    {
        // Arrange: 公開中のプランを用意する
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();

        // Act: status を一緒に送る
        $this->actingAs($admin)
            ->put(route('admin.plans.update', $plan), $this->payload(['status' => 'draft']));

        // Assert: 状態は公開中のまま
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'status' => 'published']);
    }

    /** 入力検証は新規作成と同じ。範囲外と文字数超過を弾く */
    public function test_validation_matches_store(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create(['name' => '元の名前']);

        // Act & Assert: 1項目ずつ差し替えて送り、その項目のエラーが出ることを見る
        $ng = [
            ['name', ''],                        // 必須
            ['name', str_repeat('あ', 101)],     // 100 文字超
            ['description', str_repeat('あ', 2001)],
            ['duration_days', 0],
            ['duration_days', 3651],
            ['default_meeting_quota', -1],
            ['default_meeting_quota', 1001],
            ['sort_order', -1],
        ];
        foreach ($ng as [$field, $value]) {
            $this->actingAs($admin)
                ->put(route('admin.plans.update', $plan), $this->payload([$field => $value]))
                ->assertSessionHasErrors($field);
        }

        // Assert: 1件も書き換わっていない
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'name' => '元の名前']);
    }

    /** 存在しない ID は 404 */
    public function test_missing_id_returns_404(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act & Assert
        $this->actingAs($admin)
            ->put(route('admin.plans.update', '01JXXXXXXXXXXXXXXXXXXXXXXX'), $this->payload())
            ->assertNotFound();
    }

    /** 受講生とコーチは更新できない */
    public function test_student_and_coach_cannot_update(): void
    {
        // Arrange
        $plan = Plan::factory()->draft()->create(['name' => '元の名前']);

        // Act & Assert
        foreach ([User::factory()->student(), User::factory()->coach()] as $factory) {
            $user = $factory->create();

            $this->actingAs($user)
                ->put(route('admin.plans.update', $plan), $this->payload())
                ->assertForbidden();
        }

        // Assert: 書き換わっていない
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'name' => '元の名前']);
    }
}
