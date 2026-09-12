<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 面談パックの新規作成(POST /admin/meeting-packs)を検証する。
 *
 * 見ているのは3つ。
 *  ①管理者が作れて、初期状態が「下書き」になること
 *  ②入力検証が効くこと(特に上限ちょうどが通ること = B-B-05 の教訓)
 *  ③管理者以外が作れないこと
 */
class StoreTest extends TestCase
{
    // 各テストの前に DB を作り直す。前のテストが作ったパックが残っていると件数の検証がぶれるため
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
            'name' => '5 回パック',
            'description' => '面談を 5 回追加できます。',
            'meeting_count' => 5,
            'price' => 15000,
            'stripe_price_id' => null,
            'sort_order' => 1,
        ], $override);
    }

    /** 管理者は面談パックを下書きとして作成できる */
    public function test_admin_can_create_meeting_pack_as_draft(): void
    {
        // Arrange: 管理者を1人用意する
        $admin = User::factory()->admin()->create();

        // Act: 新規作成フォームと同じ内容を POST する
        $response = $this->actingAs($admin)->post(route('admin.meeting-packs.store'), $this->payload());

        // Assert: 作成後は詳細画面へ送られ、成功メッセージが出る
        $plan = MeetingPack::firstWhere('name', '5 回パック');
        $response->assertRedirect(route('admin.meeting-packs.show', $plan));
        $response->assertSessionHas('success');

        // Assert: 画面から状態を選ばせていないので、必ず draft で入る
        $this->assertDatabaseHas('meeting_packs', [
            'name' => '5 回パック',
            'meeting_count' => 5,
            'price' => 15000,
            'status' => 'draft',
            // 作成時点では作成者と最終更新者が同じ人になる
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    /** 並び順を省略すると 0 で入る */
    public function test_sort_order_defaults_to_zero(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act: 並び順は任意項目なので、送らない場合を試す
        $this->actingAs($admin)->post(
            route('admin.meeting-packs.store'),
            $this->payload(['sort_order' => null])
        );

        // Assert: null ではなく 0 が入る(DB が NOT NULL のため)
        $this->assertDatabaseHas('meeting_packs', ['name' => '5 回パック', 'sort_order' => 0]);
    }

    /** 必須項目が空だとエラーになる */
    public function test_required_fields_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        // SKU 名・面談回数・価格の3つが必須
        foreach (['name', 'meeting_count', 'price'] as $field) {
            $this->actingAs($admin)
                ->post(route('admin.meeting-packs.store'), $this->payload([$field => null]))
                ->assertSessionHasErrors($field);
        }

        // 1件も作られていない
        $this->assertDatabaseCount('meeting_packs', 0);
    }

    /** 範囲外の数値は弾かれ、境界ちょうどは通る */
    public function test_numeric_ranges_reject_out_of_bounds_and_accept_boundaries(): void
    {
        $admin = User::factory()->admin()->create();

        // 範囲の外は弾く。0回のパックや、マイナス価格を作られては困る
        $ng = [
            ['meeting_count', 0],          // 下限 1 の1つ下
            ['meeting_count', 101],        // 上限 100 の1つ上
            ['price', -1],                 // 下限 0 の1つ下
            ['price', 1000001],            // 上限 1,000,000 の1つ上
            ['sort_order', -1],            // 下限 0 の1つ下
        ];
        foreach ($ng as [$field, $value]) {
            $this->actingAs($admin)
                ->post(route('admin.meeting-packs.store'), $this->payload([$field => $value]))
                ->assertSessionHasErrors($field);
        }

        // 上限ちょうどは通る。ここを固定しないと max の書き間違い(99 や 999999)に気づけない
        $this->actingAs($admin)->post(
            route('admin.meeting-packs.store'),
            $this->payload(['name' => '上限パック', 'meeting_count' => 100, 'price' => 1000000])
        );
        $this->assertDatabaseHas('meeting_packs', [
            'name' => '上限パック',
            'meeting_count' => 100,
            'price' => 1000000,
        ]);
    }

    /** 文字数の上限を超えると弾かれる */
    public function test_string_length_limits_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        // SKU 名は 100 文字(DB の string(100))、説明は 2000 文字(支給 Blade の maxlength のみが根拠。
        // DB は text 型なので上限の裏付けにならない)
        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $this->payload(['name' => str_repeat('あ', 101)]))
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $this->payload(['description' => str_repeat('あ', 2001)]))
            ->assertSessionHasErrors('description');
    }

    /** 価格 ID と並び順にも上限があり、境界ちょうどは通る */
    public function test_optional_fields_have_upper_limits(): void
    {
        $admin = User::factory()->admin()->create();

        // 価格 ID は 255 文字まで(DB が string(255) のため、超えると保存時に落ちる)
        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $this->payload([
                'stripe_price_id' => 'price_'.str_repeat('a', 250),   // 256 文字
            ]))
            ->assertSessionHasErrors('stripe_price_id');

        // 並び順は 65535 まで
        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $this->payload(['sort_order' => 65536]))
            ->assertSessionHasErrors('sort_order');

        // ここまでで1件も作られていない
        $this->assertDatabaseCount('meeting_packs', 0);

        // 境界ちょうどは通る。上限の書き間違いを検知するため
        $this->actingAs($admin)->post(route('admin.meeting-packs.store'), $this->payload([
            'name' => '境界パック',
            'stripe_price_id' => 'price_'.str_repeat('a', 249),       // 255 文字ちょうど
            'sort_order' => 65535,
        ]));
        $this->assertDatabaseHas('meeting_packs', ['name' => '境界パック', 'sort_order' => 65535]);
    }

    /** 受講生とコーチは作成できない */
    public function test_student_and_coach_cannot_create(): void
    {
        // Arrange & Act & Assert: どちらも 403。ルートが role:admin グループにあるため
        foreach ([User::factory()->student(), User::factory()->coach()] as $factory) {
            $this->actingAs($factory->create())
                ->post(route('admin.meeting-packs.store'), $this->payload())
                ->assertForbidden();
        }

        $this->assertDatabaseCount('meeting_packs', 0);
    }
}
