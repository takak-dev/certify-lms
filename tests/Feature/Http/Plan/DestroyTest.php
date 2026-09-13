<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlanLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講プランの削除(DELETE /admin/plans/{plan})を検証する。
 *
 * 原典「削除: 削除できる条件には制限がある(参照整合性を守る)」に対応。
 * 削除できるのは「下書き かつ 受講者0名 かつ プラン履歴0件」のときだけ。
 *
 * ⚠️ plans に softDeletes は無い。削除は物理削除で、取り消せない。
 * ⚠️ 支給 Blade の削除ボタンには状態の出し分けが無いため(plan/management/show.blade.php:86)、
 * どの状態でもボタンが出る。ブラウザ側の制御が無く、ここが唯一の防御になる。
 */
class DestroyTest extends TestCase
{
    use RefreshDatabase;

    /** 下書き・受講者0名・履歴0件なら削除できる */
    public function test_admin_can_delete_clean_draft_plan(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        // Act
        $response = $this->actingAs($admin)->delete(route('admin.plans.destroy', $plan));

        // Assert: 消したプランの詳細には戻れないので一覧へ送られる
        $response->assertRedirect(route('admin.plans.index'));
        $response->assertSessionHas('success');

        // Assert: 論理削除ではなく行そのものが消える
        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
    }

    /**
     * ⭐ 退会した(論理削除された)受講生が残っていると削除できない。
     *
     * このテストが本チケットで最も重要。退会は plan_id を消さずに論理削除するだけなので
     * (app/Services/UserWithdrawalService.php:28-35)、画面の受講者数は 0 名に見える。
     * 判定から withTrashed() を外すと、ここだけが落ちる
     * (外すと users.plan_id の外部キーに触れて 500 になる)。
     *
     * ⚠️ 支給 seeder は退会済みに plan_id を持たせないため、この状態は Factory でしか作れない。
     */
    public function test_soft_deleted_user_still_blocks_deletion(): void
    {
        // Arrange: 退会済みの受講生だけがぶら下がった下書きプラン
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();
        User::factory()->student()->withdrawn()->withPlan($plan)->create()->delete();

        // 画面に出る受講者数は 0 名(契約中が誰もいない)
        $this->assertSame(0, $plan->users()->contracted()->count());

        // Act
        $response = $this->actingAs($admin)->delete(route('admin.plans.destroy', $plan));

        // Assert: それでも削除は拒否され、プランは残る
        $response->assertRedirect();
        $response->assertSessionHas('error', 'このプランに紐づく受講生が残っているため削除できません。卒業済み・退会済みの受講生も対象です。');
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }

    /**
     * ⭐ 卒業済みの受講生が残っていても削除できない。
     *
     * 支給データで plan_id を持つのは受講中と卒業済みだけなので(退会済みは持たない)、
     * 実際の運用で最初に踏むのはこのケース。画面の「受講者数」は契約中しか数えないため
     * 0 名に見えるが、削除ガードは withTrashed() で全員を数えて止める。
     */
    public function test_graduated_user_blocks_deletion(): void
    {
        // Arrange: 卒業済みの受講生だけがぶら下がった下書きプラン
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();
        User::factory()->student()->graduated()->withPlan($plan)->create();

        // 画面に出る受講者数は 0 名(契約中が誰もいない)
        $this->assertSame(0, $plan->users()->contracted()->count());

        // Act
        $response = $this->actingAs($admin)->delete(route('admin.plans.destroy', $plan));

        // Assert: 文言が卒業済みにも触れていること(退会済みだけだと原因が分からない)
        $response->assertRedirect();
        $response->assertSessionHas('error', 'このプランに紐づく受講生が残っているため削除できません。卒業済み・退会済みの受講生も対象です。');
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }

    /** プラン履歴が残っていると削除できない(受講者が誰もいなくても) */
    public function test_plan_history_blocks_deletion(): void
    {
        // Arrange: 受講者は0名だが、過去の割当履歴が1件ある下書きプラン
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();
        UserPlanLog::factory()->create(['plan_id' => $plan->id]);

        // Act
        $response = $this->actingAs($admin)->delete(route('admin.plans.destroy', $plan));

        // Assert
        $response->assertRedirect();
        $response->assertSessionHas('error', 'このプランのプラン履歴が残っているため削除できません。');
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }

    /** 下書き以外は削除できない */
    public function test_non_draft_plan_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['published', 'archived'] as $status) {
            // Arrange: 受講者も履歴も無いので、状態だけが削除を止める
            $plan = Plan::factory()->{$status}()->create();

            // Act
            $response = $this->actingAs($admin)->delete(route('admin.plans.destroy', $plan));

            // Assert
            $response->assertRedirect();
            $response->assertSessionHas('error', '下書きのプランのみ削除できます。');
            $this->assertDatabaseHas('plans', ['id' => $plan->id]);
        }
    }

    /**
     * ⭐ 複数の条件に違反しているときは、取り消せない障害を先に伝える。decisions #127。
     *
     * 判定の順番を「状態が先」に戻すと、ここだけが落ちる。
     * 状態を先に見ると「下書きのプランのみ削除できます」と案内してしまい、
     * 管理者がアーカイブ → 下書きと2操作した末に「受講生がいる」と知ることになる。
     * その間そのプランは招待画面の選択肢から外れる。
     */
    public function test_user_guard_is_reported_before_status_guard(): void
    {
        // Arrange: 状態・受講者・履歴の3つすべてに違反する公開中プラン
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();
        User::factory()->student()->inProgress()->withPlan($plan)->create();
        UserPlanLog::factory()->create(['plan_id' => $plan->id]);

        // Act
        $response = $this->actingAs($admin)->delete(route('admin.plans.destroy', $plan));

        // Assert: 3つとも事実だが、管理者に伝えるのは自力で直せない受講者の話
        $response->assertSessionHas('error', 'このプランに紐づく受講生が残っているため削除できません。卒業済み・退会済みの受講生も対象です。');
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }

    /** 削除できない場合は JSON 経路では 409 */
    public function test_blocked_deletion_returns_409_for_json(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();

        // Act & Assert
        $this->actingAs($admin)
            ->deleteJson(route('admin.plans.destroy', $plan))
            ->assertStatus(409);
    }

    /** 受講生とコーチは削除できない */
    public function test_student_and_coach_cannot_delete(): void
    {
        // Arrange: 削除条件を満たしたプランを使い、403 の理由が権限だけになるようにする
        $plan = Plan::factory()->draft()->create();

        // Act & Assert
        foreach ([User::factory()->student(), User::factory()->coach()] as $factory) {
            $user = $factory->create();

            $this->actingAs($user)
                ->delete(route('admin.plans.destroy', $plan))
                ->assertForbidden();
        }

        // Assert: 消えていない
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }
}
