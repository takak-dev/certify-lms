<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 面談パックの状態遷移(publish / archive / unarchive)を検証する。
 *
 * 許される遷移は3本だけ。
 *   下書き --publish--> 公開中 --archive--> アーカイブ --unarchive--> 下書き
 *
 * 原典「不正な順序での状態変更はできない」に対応する。
 * 画面はボタンを状態で出し分けているが、それはブラウザの中だけの制御なので、
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
            $plan = MeetingPack::factory()->{$from}()->create([
                'created_by_user_id' => $author->id,
                'updated_by_user_id' => $author->id,
            ]);

            // Act
            $response = $this->actingAs($operator)
                ->post(route("admin.meeting-packs.{$action}", $plan));

            // Assert: 同じ詳細画面に留まり、成功メッセージが出る
            $response->assertRedirect(route('admin.meeting-packs.show', $plan));
            $response->assertSessionHas('success');

            // Assert: 状態が変わり、最終更新者が操作者になる。作成者は変わらない
            $this->assertDatabaseHas('meeting_packs', [
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
            ['publish', 'published', '下書きの面談パックのみ公開できます。'],
            ['publish', 'archived', '下書きの面談パックのみ公開できます。'],
            // archive できるのは公開中だけ
            ['archive', 'draft', '公開中の面談パックのみアーカイブできます。'],
            ['archive', 'archived', '公開中の面談パックのみアーカイブできます。'],
            // unarchive できるのはアーカイブ済みだけ
            ['unarchive', 'draft', 'アーカイブ済みの面談パックのみ下書きに戻せます。'],
            ['unarchive', 'published', 'アーカイブ済みの面談パックのみ下書きに戻せます。'],
        ];

        foreach ($cases as [$action, $from, $message]) {
            // Arrange
            $plan = MeetingPack::factory()->{$from}()->create();

            // Act: 画面にボタンが出ない状態から、URL を直接叩く
            $response = $this->actingAs($admin)
                ->post(route("admin.meeting-packs.{$action}", $plan));

            // Assert: 直前の画面へ戻され、理由が表示される
            $response->assertRedirect();
            $response->assertSessionHas('error', $message);

            // Assert: 状態は動いていない
            $this->assertDatabaseHas('meeting_packs', ['id' => $plan->id, 'status' => $from]);
        }
    }

    /** 不正な遷移は JSON 経路では 409 になる */
    public function test_invalid_transition_returns_409_for_json(): void
    {
        // Arrange: 公開中のパックを、もう一度公開しようとする
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->published()->create();

        // Act
        $response = $this->actingAs($admin)
            ->postJson(route('admin.meeting-packs.publish', $plan));

        // Assert: 409 Conflict
        $response->assertStatus(409);
    }

    /** 受講生とコーチはどの遷移も実行できない */
    public function test_student_and_coach_cannot_transition(): void
    {
        // Arrange: それぞれ「遷移が成立する状態」で用意する。
        // 403 の理由が状態ではなく権限であることを確かめるため
        $plans = [
            'publish' => MeetingPack::factory()->draft()->create(),
            'archive' => MeetingPack::factory()->published()->create(),
            'unarchive' => MeetingPack::factory()->archived()->create(),
        ];

        foreach ([User::factory()->student(), User::factory()->coach()] as $factory) {
            $user = $factory->create();
            foreach ($plans as $action => $plan) {
                $this->actingAs($user)
                    ->post(route("admin.meeting-packs.{$action}", $plan))
                    ->assertForbidden();
            }
        }

        // Assert: 3件とも状態が変わっていない
        $this->assertDatabaseHas('meeting_packs', ['id' => $plans['publish']->id, 'status' => 'draft']);
        $this->assertDatabaseHas('meeting_packs', ['id' => $plans['archive']->id, 'status' => 'published']);
        $this->assertDatabaseHas('meeting_packs', ['id' => $plans['unarchive']->id, 'status' => 'archived']);
    }
}
