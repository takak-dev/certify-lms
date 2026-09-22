<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 面談パックの削除(DELETE /admin/meeting-packs/{plan})を検証する。
 *
 * 原典「削除(物理削除。ただし削除できる状態には制限があり、公開中の面談パックは削除できない)」に対応。
 * 見ているのは3つ。
 *  ①下書き・アーカイブは消せて、行が本当に消えること(物理削除)
 *  ②公開中は消せないこと。しかも画面のボタンを隠すだけでなく、直接送っても拒否されること
 *  ③管理者以外は消せないこと
 */
class DestroyTest extends TestCase
{
    use RefreshDatabase;

    /** 下書きとアーカイブは削除できる */
    public function test_draft_and_archived_can_be_deleted(): void
    {
        // Arrange: 消せるはずの2状態を用意する
        $admin = User::factory()->admin()->create();
        $draft = MeetingPack::factory()->draft()->create();
        $archived = MeetingPack::factory()->archived()->create();

        // Act & Assert: どちらも一覧へ戻り、成功メッセージが出る
        foreach ([$draft, $archived] as $plan) {
            $response = $this->actingAs($admin)->delete(route('admin.meeting-packs.destroy', $plan));

            $response->assertRedirect(route('admin.meeting-packs.index'));
            $response->assertSessionHas('success');
            // 物理削除なので、行そのものが消える(論理削除なら残って deleted_at が入る)
            $this->assertDatabaseMissing('meeting_packs', ['id' => $plan->id]);
        }
    }

    /** 公開中は削除できず、行が残る */
    public function test_published_cannot_be_deleted(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $published = MeetingPack::factory()->published()->create();

        // Act: 画面には削除ボタンが出ないが、URL を直接叩いた場合を再現する
        $response = $this->actingAs($admin)->delete(route('admin.meeting-packs.destroy', $published));

        // Assert: 直前の画面へ戻され、理由が error として表示される
        $response->assertRedirect();
        $response->assertSessionHas('error', '公開中の面談パックは削除できません。');

        // Assert: 行は残っている
        $this->assertDatabaseHas('meeting_packs', ['id' => $published->id]);
    }

    /** 公開中の削除は JSON 経路では 409 になる */
    public function test_published_delete_returns_409_for_json(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $published = MeetingPack::factory()->published()->create();

        // Act: JSON で送ると Handler はリダイレクトせず、ステータスコードをそのまま返す
        $response = $this->actingAs($admin)->deleteJson(route('admin.meeting-packs.destroy', $published));

        // Assert: 409 Conflict(業務ルール上の衝突)
        $response->assertStatus(409);
    }

    /** 受講生とコーチは削除できない */
    public function test_student_and_coach_cannot_delete(): void
    {
        // Arrange: 消せる状態(下書き)にしておく。403 の理由が状態ではなく権限であることを確かめるため
        $plan = MeetingPack::factory()->draft()->create();

        // Act & Assert
        foreach ([User::factory()->student(), User::factory()->coach()] as $factory) {
            $this->actingAs($factory->create())
                ->delete(route('admin.meeting-packs.destroy', $plan))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('meeting_packs', ['id' => $plan->id]);
    }

    /**
     * 購入履歴のあるパックは、アーカイブ済みでも削除できない(decisions #38 / #123)。
     *
     * ⚠️ payments.meeting_pack_id は restrictOnDelete なので、この判定が無いと
     *    外部キー違反で 500 になる。データは守られるが、利用者には何が起きたか分からない。
     */
    public function test_pack_with_purchase_history_cannot_be_deleted(): void
    {
        // Arrange: アーカイブ済み(＝状態だけ見れば消せる)だが、購入が 1 件ある
        $admin = User::factory()->admin()->create();
        $archived = MeetingPack::factory()->archived()->create();
        Payment::factory()->succeeded()->forPack($archived)->create();

        // Act
        $response = $this->actingAs($admin)->delete(route('admin.meeting-packs.destroy', $archived));

        // Assert: 500 ではなく 409 → 直前の画面へ戻り、理由が文言で伝わる
        $response->assertRedirect();
        $response->assertSessionHas('error', '購入履歴のある面談パックは削除できません。');

        // Assert: 行は残る
        $this->assertDatabaseHas('meeting_packs', ['id' => $archived->id]);
    }

    /** 購入履歴が無ければアーカイブ済みは今までどおり消せる(拒否しすぎていないことの確認) */
    public function test_archived_pack_without_purchase_history_is_still_deletable(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $archived = MeetingPack::factory()->archived()->create();

        // Act
        $response = $this->actingAs($admin)->delete(route('admin.meeting-packs.destroy', $archived));

        // Assert
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('meeting_packs', ['id' => $archived->id]);
    }

    /**
     * 未完了(pending / failed)の購入でも削除は止める(decisions #232)。
     *
     * ⚠️ 「決済画面で離脱しただけの pending は数えない」という解釈も検討したが、
     *    payments.meeting_pack_id は restrictOnDelete なので、アプリが許しても DB が
     *    外部キー違反(500)で止める。**アプリの解釈を DB 制約に合わせる**。
     *    画面から消したいだけならアーカイブで足りる。
     */
    public function test_pending_payment_also_blocks_deletion(): void
    {
        // Arrange: 未完了の購入だけがあるアーカイブ済みパック
        $admin = User::factory()->admin()->create();
        $archived = MeetingPack::factory()->archived()->create();
        Payment::factory()->pending()->forPack($archived)->create();

        // Act
        $response = $this->actingAs($admin)->delete(route('admin.meeting-packs.destroy', $archived));

        // Assert: 500 ではなく 409 で、理由が文言で伝わる
        $response->assertSessionHas('error', '購入履歴のある面談パックは削除できません。');
        $this->assertDatabaseHas('meeting_packs', ['id' => $archived->id]);
    }

    /** 返金済みの購入は「売れた実績」なので削除を止める(decisions #232) */
    public function test_refunded_payment_still_blocks_deletion(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $archived = MeetingPack::factory()->archived()->create();
        Payment::factory()->refunded()->forPack($archived)->create();

        // Act
        $response = $this->actingAs($admin)->delete(route('admin.meeting-packs.destroy', $archived));

        // Assert
        $response->assertSessionHas('error', '購入履歴のある面談パックは削除できません。');
        $this->assertDatabaseHas('meeting_packs', ['id' => $archived->id]);
    }
}
