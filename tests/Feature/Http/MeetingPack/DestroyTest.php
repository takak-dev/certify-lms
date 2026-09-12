<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
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
}
