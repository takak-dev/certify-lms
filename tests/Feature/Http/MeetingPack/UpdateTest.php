<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 面談パックの更新(PATCH /admin/meeting-packs/{plan})を検証する。
 *
 * 新規作成と重なる検証(文字数・範囲)は StoreTest に任せ、ここは更新固有の3点を見る。
 *  ①基本情報が書き換わり、最終更新者が操作者になること
 *  ②状態がこのフォームからは変えられないこと(改ざんして送っても無視される)
 *  ③作成者は変わらないこと
 */
class UpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => '10 回パック',
            'description' => '面談を 10 回追加できます。',
            'meeting_count' => 10,
            'price' => 28000,
            'stripe_price_id' => null,
            'sort_order' => 2,
        ], $override);
    }

    /** 管理者は基本情報を更新できる */
    public function test_admin_can_update_basic_info(): void
    {
        // Arrange: 作成者と更新者を別人にして、どちらが書き換わるか見分けられるようにする
        $author = User::factory()->admin()->create();
        $editor = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create([
            'name' => '5 回パック',
            'created_by_user_id' => $author->id,
            'updated_by_user_id' => $author->id,
        ]);

        // Act
        $response = $this->actingAs($editor)
            ->patch(route('admin.meeting-packs.update', $plan), $this->payload());

        // Assert: 詳細画面へ戻り、成功メッセージが出る
        $response->assertRedirect(route('admin.meeting-packs.show', $plan));
        $response->assertSessionHas('success');

        // Assert: 値が書き換わり、最終更新者だけが操作者に変わる
        $this->assertDatabaseHas('meeting_packs', [
            'id' => $plan->id,
            'name' => '10 回パック',
            'meeting_count' => 10,
            'price' => 28000,
            // 作った人は変わらない
            'created_by_user_id' => $author->id,
            'updated_by_user_id' => $editor->id,
        ]);
    }

    /** 編集フォームからは状態を変えられない */
    public function test_status_cannot_be_changed_from_edit_form(): void
    {
        // Arrange: 下書きのパックを用意する
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create();

        // Act: フォームに無い status を混ぜて送る(画面を改ざんした場合を再現)
        $this->actingAs($admin)->patch(
            route('admin.meeting-packs.update', $plan),
            $this->payload(['status' => 'published'])
        );

        // Assert: 下書きのまま。検証ルールに書いていない項目は validated() に乗らず Action へ届かない
        $this->assertDatabaseHas('meeting_packs', ['id' => $plan->id, 'status' => 'draft']);
    }

    /** 必須項目が空だとエラーになり、値は変わらない */
    public function test_validation_error_keeps_original_values(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create(['name' => '5 回パック']);

        // Act
        $response = $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $plan), $this->payload(['name' => null]));

        // Assert: エラーになり、元の名前が残る
        $response->assertSessionHasErrors('name');
        $this->assertDatabaseHas('meeting_packs', ['id' => $plan->id, 'name' => '5 回パック']);
    }

    /** 受講生とコーチは更新できない */
    public function test_student_and_coach_cannot_update(): void
    {
        // Arrange
        $plan = MeetingPack::factory()->draft()->create(['name' => '5 回パック']);

        // Act & Assert: どちらも 403
        foreach ([User::factory()->student(), User::factory()->coach()] as $factory) {
            $this->actingAs($factory->create())
                ->patch(route('admin.meeting-packs.update', $plan), $this->payload())
                ->assertForbidden();
        }

        // 値は変わっていない
        $this->assertDatabaseHas('meeting_packs', ['id' => $plan->id, 'name' => '5 回パック']);
    }

    /**
     * 価格の下限 100 円は編集時にも効く(decisions #212 は Store / Update の両方を名指し)。
     *
     * ⚠️ 実測(2026-09-22): Stripe は JPY 合計 ¥50 未満の Checkout Session を作れないため、
     *    ¥1〜¥49 のパックを公開すると購入ボタンが 409 で止まる。
     */
    public function test_price_lower_boundary_is_enforced_on_update(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->withPrice(3000)->create();

        // Act & Assert: 下限の 1 つ下は弾く
        $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $plan), $this->payload(['price' => 99]))
            ->assertSessionHasErrors('price');
        $this->assertDatabaseHas('meeting_packs', ['id' => $plan->id, 'price' => 3000]);

        // Act & Assert: 下限ちょうどは通る
        $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $plan), $this->payload(['price' => 100]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('meeting_packs', ['id' => $plan->id, 'price' => 100]);
    }
}
