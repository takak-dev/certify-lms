<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講プランの状態遷移(publish / archive / unarchive)を検証する。
 *
 * 原典「下書き・公開中・アーカイブの間でライフサイクルに沿って状態を切り替えられる」
 * 「不正な順序での状態変更はできない」に対応。
 *
 * 画面はボタンを状態で出し分けるが、それはブラウザの中だけの制御。
 * ここでは「ボタンが無いはずの状態から直接送った場合」を全パターン試す。
 */
class TransitionTest extends TestCase
{
    use RefreshDatabase;

    /** 正しい順序の3遷移は成功し、状態と最終更新者が変わる */
    public function test_valid_transitions_change_status(): void
    {
        // Arrange: 作成者と操作者を分けて、最終更新者だけが変わることを見分けられるようにする
        $author = User::factory()->admin()->create();
        $operator = User::factory()->admin()->create();

        // 遷移名 => [開始状態のファクトリ, 期待する状態]
        $cases = [
            'publish' => ['draft', 'published'],
            'archive' => ['published', 'archived'],
            // アーカイブの戻り先は公開中ではなく下書き(画面のボタンが「下書きへ戻す」)
            'unarchive' => ['archived', 'draft'],
        ];

        foreach ($cases as $action => [$from, $to]) {
            // Arrange
            $plan = Plan::factory()->{$from}()->create([
                'created_by_user_id' => $author->id,
                'updated_by_user_id' => $author->id,
            ]);

            // Act
            $response = $this->actingAs($operator)
                ->post(route("admin.plans.{$action}", $plan));

            // Assert: 同じ詳細画面に留まり、成功メッセージが出る
            $response->assertRedirect(route('admin.plans.show', $plan));
            $response->assertSessionHas('success');

            // Assert: 状態が変わり、最終更新者が操作者になる。作成者は変わらない
            $this->assertDatabaseHas('plans', [
                'id' => $plan->id,
                'status' => $to,
                'created_by_user_id' => $author->id,
                'updated_by_user_id' => $operator->id,
            ]);
        }
    }

    /** 順序を飛ばす遷移は拒否され、状態が変わらない */
    public function test_invalid_transitions_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        // 3遷移 × 3状態のうち、許されない6通りをすべて試す
        $cases = [
            // publish できるのは下書きだけ
            ['publish', 'published', '下書きのプランのみ公開できます。'],
            ['publish', 'archived', '下書きのプランのみ公開できます。'],
            // archive できるのは公開中だけ
            ['archive', 'draft', '公開中のプランのみアーカイブできます。'],
            ['archive', 'archived', '公開中のプランのみアーカイブできます。'],
            // unarchive できるのはアーカイブ済みだけ
            ['unarchive', 'draft', 'アーカイブ済みのプランのみ下書きに戻せます。'],
            ['unarchive', 'published', 'アーカイブ済みのプランのみ下書きに戻せます。'],
        ];

        foreach ($cases as [$action, $from, $message]) {
            // Arrange
            $plan = Plan::factory()->{$from}()->create();

            // Act: 画面にボタンが出ない状態から、URL を直接叩く
            $response = $this->actingAs($admin)
                ->post(route("admin.plans.{$action}", $plan));

            // Assert: 直前の画面へ戻され、理由が表示される
            $response->assertRedirect();
            $response->assertSessionHas('error', $message);

            // Assert: 状態は動いていない
            $this->assertDatabaseHas('plans', ['id' => $plan->id, 'status' => $from]);
        }
    }

    /**
     * アーカイブしても、そのプランで受講中のユーザーの参照は切れない。
     *
     * 原典「アーカイブ後は招待画面 / プラン延長画面の選択肢から外れるが、
     * 受講中ユーザーの参照は維持される」に対応する。
     * 招待・延長から外れることは既存テストが担保しているので
     * (Invitation/StoreTest::test_archived_plan_id_is_rejected と
     *  UseCases/Plan/ExtendCourseActionTest::test_throws_when_plan_is_archived)、
     * ここでは「外さないほう」を検査する。
     */
    public function test_archiving_keeps_existing_users_attached(): void
    {
        // Arrange: 公開中のプランに受講中のユーザーを1人ぶら下げる
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();
        $student = User::factory()->student()->inProgress()->withPlan($plan)->create(['name' => '受講中の人']);

        // Act: アーカイブする
        $response = $this->actingAs($admin)->post(route('admin.plans.archive', $plan));

        // Assert: まずアーカイブが成立したことを確かめる。
        // ここを省くと「遷移が失敗しても受講者は残る」だけを見る空振りのテストになる
        $response->assertRedirect(route('admin.plans.show', $plan));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'status' => 'archived']);

        // Assert: ユーザー側のプラン情報は何も変わらない
        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'plan_id' => $plan->id,
            'status' => 'in_progress',
        ]);

        // Assert: 管理画面の受講者一覧にも残り続ける
        $this->actingAs($admin)
            ->get(route('admin.plans.show', $plan))
            ->assertOk()
            ->assertSee('受講中の人');
    }

    /** 不正な遷移は JSON 経路では 409 になる */
    public function test_invalid_transition_returns_409_for_json(): void
    {
        // Arrange: 公開中のプランを、もう一度公開しようとする
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();

        // Act
        $response = $this->actingAs($admin)
            ->postJson(route('admin.plans.publish', $plan));

        // Assert: 409 Conflict
        $response->assertStatus(409);
    }

    /** 受講生とコーチはどの遷移も実行できない */
    public function test_student_and_coach_cannot_transition(): void
    {
        // Arrange: それぞれ「遷移が成立する状態」で用意する。
        // 403 の理由が状態ではなく権限であることを確かめるため
        $plans = [
            'publish' => Plan::factory()->draft()->create(),
            'archive' => Plan::factory()->published()->create(),
            'unarchive' => Plan::factory()->archived()->create(),
        ];

        foreach ([User::factory()->student(), User::factory()->coach()] as $factory) {
            $user = $factory->create();
            foreach ($plans as $action => $plan) {
                $this->actingAs($user)
                    ->post(route("admin.plans.{$action}", $plan))
                    ->assertForbidden();
            }
        }

        // Assert: 3件とも状態が変わっていない
        $this->assertDatabaseHas('plans', ['id' => $plans['publish']->id, 'status' => 'draft']);
        $this->assertDatabaseHas('plans', ['id' => $plans['archive']->id, 'status' => 'published']);
        $this->assertDatabaseHas('plans', ['id' => $plans['unarchive']->id, 'status' => 'archived']);
    }
}
