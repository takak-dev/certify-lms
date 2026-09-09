<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 解決マークの切替（POST /qa-board/{thread}/resolve、/unresolve）の検証。
 *
 * 状態遷移は既存も専用ファイルに分けている（手本: tests/Feature/Http/Certification/PublishTest.php）。
 * 切替できるのは投稿者本人のみで、管理者の代行はスコープ外（原典）。
 */
class ResolveTest extends TestCase
{
    use RefreshDatabase;

    /** 公開中の資格に紐づくスレッドを、指定した投稿者で作る */
    private function threadOf(User $author): QaThread
    {
        $certification = Certification::factory()->published()->create();

        return QaThread::factory()->forUser($author)->forCertification($certification)->create();
    }

    public function test_author_can_toggle_resolved_state(): void
    {
        // Arrange
        $author = User::factory()->student()->create();
        $thread = $this->threadOf($author);

        // Act & Assert: 解決済にすると resolved_at が入る
        $this->actingAs($author)->post(route('qa-board.resolve', $thread))->assertSessionHas('success');
        $thread->refresh();
        $this->assertSame(QaThreadStatus::Resolved, $thread->status);
        $this->assertNotNull($thread->resolved_at);

        // Act & Assert: 未解決に戻すと resolved_at は消える（状態と時刻をセットで扱う）
        $this->actingAs($author)->post(route('qa-board.unresolve', $thread))->assertSessionHas('success');
        $thread->refresh();
        $this->assertSame(QaThreadStatus::Open, $thread->status);
        $this->assertNull($thread->resolved_at);
    }

    public function test_resolving_twice_does_not_move_the_resolved_time(): void
    {
        // Arrange: 二重送信や戻るボタンでの再送を想定する。冪等でないと解決時刻が後ろにずれていく
        $author = User::factory()->student()->create();
        $thread = $this->threadOf($author);

        $this->actingAs($author)->post(route('qa-board.resolve', $thread));
        $firstResolvedAt = $thread->refresh()->resolved_at;

        // Act: 1時間後にもう一度同じ操作を送る
        $this->travel(1)->hours();
        $this->actingAs($author)->post(route('qa-board.resolve', $thread));

        // Assert: 最初に解決した時刻のまま
        $this->assertTrue($firstResolvedAt->equalTo($thread->refresh()->resolved_at));
    }

    public function test_other_users_cannot_toggle_resolved_state(): void
    {
        // Arrange: 解決マークは投稿者本人のみ。管理者の代行もできない（原典のスコープ外）
        $author = User::factory()->student()->create();
        $thread = $this->threadOf($author);
        $otherStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        // Act & Assert
        $this->actingAs($otherStudent)->post(route('qa-board.resolve', $thread))->assertForbidden();
        $this->actingAs($coach)->post(route('qa-board.resolve', $thread))->assertForbidden();
        $this->assertSame(QaThreadStatus::Open, $thread->refresh()->status);
    }
}
