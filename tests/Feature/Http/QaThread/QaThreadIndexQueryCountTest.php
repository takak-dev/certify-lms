<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 質問掲示板一覧（`qa-board.index`）と詳細（`qa-board.show`）の N+1 非回帰を検証する Feature テスト。
 *
 * 原典が「一覧表示はスレッド件数が増えても取得時間が線形に増えないように関連データを効率的に取得する」と
 * 明記している要件そのものを機械で守る。手本: tests/Feature/Http/MockExam/MockExamIndexQueryCountTest.php
 *
 * 一覧は投稿者・資格・回答数（`with` + `withCount`）、詳細は回答とその投稿者（`replies.user`）を
 * 先読みしているので、件数を増やしてもクエリ本数は変わらない。
 */
class QaThreadIndexQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_query_count_does_not_grow_with_thread_count(): void
    {
        // Arrange: 受講生 + 公開中の資格に紐づくスレッド2件（基準）
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        QaThread::factory()->forCertification($certification)->count(2)->create();

        // Act: 基準を計測 → スレッドを10件追加して再計測（1ページ20件に収まる件数）
        $baseline = $this->countQueriesFor(
            fn () => $this->actingAs($student)->get(route('qa-board.index'))
        );
        QaThread::factory()->forCertification($certification)->count(10)->create();
        $scaled = $this->countQueriesFor(
            fn () => $this->actingAs($student)->get(route('qa-board.index'))
        );

        // Assert: 件数が増えてもクエリ本数はほぼ一定（N+1 なら件数分増える）
        $this->assertLessThanOrEqual(
            $baseline + 3,
            $scaled,
            "質問掲示板一覧で N+1 が再発している (基準 {$baseline} → 増加後 {$scaled})。"
            .'投稿者・資格を with()、回答数を withCount() で一括取得しているか確認',
        );
    }

    public function test_show_query_count_does_not_grow_with_reply_count(): void
    {
        // Arrange: 自分のスレッドに自分の回答を並べる。
        // 回答カードは1件ごとに can('update', $reply) を呼び、Policy が親スレッドを辿るため、
        // 親を差し込んでいないと「自分の回答の件数」に比例してクエリが増える
        $author = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->forUser($author)->forCertification($certification)->create();
        QaReply::factory()->forThread($thread)->forUser($author)->count(2)->create();

        // Act
        $baseline = $this->countQueriesFor(
            fn () => $this->actingAs($author)->get(route('qa-board.show', $thread))
        );
        QaReply::factory()->forThread($thread)->forUser($author)->count(10)->create();
        $scaled = $this->countQueriesFor(
            fn () => $this->actingAs($author)->get(route('qa-board.show', $thread))
        );

        // Assert
        $this->assertLessThanOrEqual(
            $baseline + 3,
            $scaled,
            "スレッド詳細で N+1 が再発している (基準 {$baseline} → 増加後 {$scaled})。"
            .'回答とその投稿者を先読みし、回答に親スレッドを差し込んでいるか確認',
        );
    }

    private function countQueriesFor(\Closure $closure): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $closure();

        return $count;
    }
}
