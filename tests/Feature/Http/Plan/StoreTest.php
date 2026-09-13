<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講プランの新規作成(POST /admin/plans)を検証する。
 *
 * 原典「新規作成: 初期状態は下書きで作成」と「入力検証(新規作成 / 編集)」に対応。
 */
class StoreTest extends TestCase
{
    // 各テストの前に DB を作り直す。前のテストが作ったプランが残っていると件数の検証がぶれるため
    use RefreshDatabase;

    /**
     * 送信データのひな形。1項目だけ変えて試したいので、差分を引数で受け取る。
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => '3 ヶ月プラン 12 回',
            'description' => '標準的な学習期間。',
            'duration_days' => 90,
            'default_meeting_quota' => 12,
            'sort_order' => 1,
        ], $override);
    }

    /** 管理者はプランを下書きとして作成できる */
    public function test_admin_can_create_plan_as_draft(): void
    {
        // Arrange: 管理者を1人用意する
        $admin = User::factory()->admin()->create();

        // Act: 新規作成フォームと同じ内容を POST する
        $response = $this->actingAs($admin)->post(route('admin.plans.store'), $this->payload());

        // Assert: 作成後は詳細画面へ送られ、成功メッセージが出る
        $plan = Plan::firstWhere('name', '3 ヶ月プラン 12 回');
        $response->assertRedirect(route('admin.plans.show', $plan));
        $response->assertSessionHas('success');

        // Assert: 画面から状態を選ばせていないので、必ず draft で入る
        $this->assertDatabaseHas('plans', [
            'name' => '3 ヶ月プラン 12 回',
            'duration_days' => 90,
            'default_meeting_quota' => 12,
            'status' => 'draft',
            // 作成時点では作成者と最終更新者が同じ人になる
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    /**
     * フォームに無い項目を足して送っても無視される。
     *
     * rules() に書いた項目だけが validated() に乗り、status は Action が決め打ちする。
     * Controller を request->all() に書き換えると、このテストが落ちる。
     */
    public function test_tampered_status_and_author_are_ignored(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();

        // Act: 画面には無い status と created_by_user_id を一緒に送る
        $this->actingAs($admin)->post(route('admin.plans.store'), $this->payload([
            'status' => 'published',
            'created_by_user_id' => $other->id,
        ]));

        // Assert: 状態は下書き、作成者は操作した本人
        $this->assertDatabaseHas('plans', [
            'name' => '3 ヶ月プラン 12 回',
            'status' => 'draft',
            'created_by_user_id' => $admin->id,
        ]);
    }

    /** 並び順を省略すると 0 で入る */
    public function test_sort_order_defaults_to_zero(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act: sort_order を送らない(任意項目)
        $payload = $this->payload();
        unset($payload['sort_order']);
        $this->actingAs($admin)->post(route('admin.plans.store'), $payload);

        // Assert: DB の default(0) ではなく Action が明示的に 0 を入れている
        $this->assertDatabaseHas('plans', ['name' => '3 ヶ月プラン 12 回', 'sort_order' => 0]);
    }

    /** 必須項目が無いと弾かれる */
    public function test_required_fields_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['name', 'duration_days', 'default_meeting_quota'] as $field) {
            $payload = $this->payload();
            unset($payload[$field]);

            $this->actingAs($admin)
                ->post(route('admin.plans.store'), $payload)
                ->assertSessionHasErrors($field);
        }

        // 1件も作られていない
        $this->assertDatabaseCount('plans', 0);
    }

    /** 範囲外の数値は弾かれ、境界ちょうどは通る */
    public function test_numeric_ranges_reject_out_of_bounds_and_accept_boundaries(): void
    {
        $admin = User::factory()->admin()->create();

        // 範囲の外は弾く。0 日のプランや、マイナスの面談回数を作られては困る
        $ng = [
            ['duration_days', 0],                // 下限 1 の1つ下
            ['duration_days', 3651],             // 上限 3650 の1つ上
            ['default_meeting_quota', -1],       // 下限 0 の1つ下
            ['default_meeting_quota', 1001],     // 上限 1000 の1つ上
            ['sort_order', -1],                  // 下限 0 の1つ下
        ];
        foreach ($ng as [$field, $value]) {
            $this->actingAs($admin)
                ->post(route('admin.plans.store'), $this->payload([$field => $value]))
                ->assertSessionHasErrors($field);
        }

        // 境界ちょうどは通る。ここを固定しないと max の書き間違い(3649 や 999)に気づけない
        $this->actingAs($admin)->post(route('admin.plans.store'), $this->payload([
            'name' => '上限プラン',
            'duration_days' => 3650,
            'default_meeting_quota' => 1000,
            'sort_order' => 65535,
        ]));
        $this->assertDatabaseHas('plans', [
            'name' => '上限プラン',
            'duration_days' => 3650,
            'default_meeting_quota' => 1000,
            'sort_order' => 65535,
        ]);
    }

    /** 文字数の上限を超えると弾かれる */
    public function test_string_length_limits_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        // プラン名は 100 文字(DB の string(100) と支給 Blade の maxlength が一致)
        $this->actingAs($admin)
            ->post(route('admin.plans.store'), $this->payload(['name' => str_repeat('あ', 101)]))
            ->assertSessionHasErrors('name');

        // 説明は 2000 文字(支給 Blade の :maxlength と hint が根拠: create.blade.php:42-43。
        // DB は text 型なので上限の裏付けにならない)
        $this->actingAs($admin)
            ->post(route('admin.plans.store'), $this->payload(['description' => str_repeat('あ', 2001)]))
            ->assertSessionHasErrors('description');
    }

    /** 受講生とコーチは作成できない */
    public function test_student_and_coach_cannot_create(): void
    {
        foreach ([User::factory()->student(), User::factory()->coach()] as $factory) {
            $user = $factory->create();

            $this->actingAs($user)
                ->post(route('admin.plans.store'), $this->payload())
                ->assertForbidden();
        }

        $this->assertDatabaseCount('plans', 0);
    }
}
