<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 配信履歴の一覧（GET /admin/announcements）の検証。
 *
 * 守りたいのは 3 つ——①管理者しか開けないこと ②時系列（新しい順）に並ぶこと
 * ③行が増えてもクエリ本数が増えないこと。
 * ③は要件に直接の記述が無いが、一覧が 3 つの関連（対象資格 / 対象受講生 / 配信者）を
 * 参照するため、素直に書くと N+1 になる。T-B-01 と同じ型の劣化をここで止める。
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_the_history(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        Announcement::factory()->createdBy($admin)->create(['title' => '運営からの連絡']);

        // Act
        $response = $this->actingAs($admin)->get(route('admin.announcements.index'));

        // Assert
        $response->assertOk();
        $response->assertSee('運営からの連絡');
    }

    /**
     * 管理画面なので受講生とコーチは入れない。
     * ルート側の role:admin ミドルウェアと AnnouncementPolicy の二重で守っている。
     */
    public function test_students_and_coaches_are_rejected(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        foreach ([$student, $coach] as $user) {
            // Act
            $response = $this->actingAs($user)->get(route('admin.announcements.index'));

            // Assert
            $response->assertForbidden();
        }
    }

    public function test_guests_are_redirected_to_login(): void
    {
        // Act
        $response = $this->get(route('admin.announcements.index'));

        // Assert
        $response->assertRedirect(route('login'));
    }

    /**
     * 要件「配信履歴の一覧を時系列で閲覧できる」。新しい配信が先頭に来る。
     */
    public function test_history_is_ordered_by_dispatched_at_desc(): void
    {
        // Arrange: 配信時刻だけが違う 3 件。作成順とは逆の並びになるはず
        $admin = User::factory()->admin()->create();
        $old = Announcement::factory()->createdBy($admin)->dispatched(1, '2026-01-01 10:00:00')->create();
        $newest = Announcement::factory()->createdBy($admin)->dispatched(1, '2026-03-01 10:00:00')->create();
        $middle = Announcement::factory()->createdBy($admin)->dispatched(1, '2026-02-01 10:00:00')->create();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.announcements.index'));

        // Assert
        $response->assertOk();
        $response->assertViewHas('announcements', function ($announcements) use ($newest, $middle, $old): bool {
            return $announcements->pluck('id')->all() === [$newest->id, $middle->id, $old->id];
        });
    }

    /**
     * 同じ配信時刻が並んでも順序がぶれないこと（decisions #93）。
     *
     * 秒まで同じ配信は現実に起きる（二度押し・連続配信）。第2ソートが無いと MySQL が返す順序が
     * 不定になり、ページを送ったときに同じ行が二度出たり消えたりする。
     */
    public function test_same_dispatched_at_is_ordered_by_primary_key(): void
    {
        // Arrange: 配信時刻がまったく同じ 3 件
        $admin = User::factory()->admin()->create();
        $at = '2026-03-01 10:00:00';
        $announcements = Announcement::factory()->count(3)->createdBy($admin)->dispatched(1, $at)->create();

        // 期待値は主キーの降順。ULID は生成順に大きくなるため、作成の逆順になる
        $expected = $announcements->pluck('id')->sort()->reverse()->values()->all();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.announcements.index'));

        // Assert
        $response->assertViewHas('announcements', fn ($page): bool => $page->pluck('id')->all() === $expected);
    }

    /** 1 ページ 20 件（decisions #94。既存の一覧 Action の既定に揃えた） */
    public function test_paginates_at_twenty_rows(): void
    {
        // Arrange: 21 件入れて 1 ページ目が 20 件で切れることを見る
        $admin = User::factory()->admin()->create();
        Announcement::factory()->count(21)->createdBy($admin)->create();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.announcements.index'));

        // Assert
        $response->assertViewHas('announcements', function ($announcements): bool {
            return $announcements->count() === 20 && $announcements->total() === 21;
        });
    }

    /**
     * 行が増えてもクエリ本数が変わらないこと（N+1 の検知）。
     *
     * 件数ではなく「増えないこと」を見るのは、本数の絶対値が実装の細部で揺れるため。
     * 3 行と 9 行で同じ本数なら、行ごとの追加クエリが無いと言える。
     */
    public function test_query_count_does_not_grow_with_rows(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $count = fn (): int => $this->countQueriesWhileOpeningIndex($admin);

        Announcement::factory()->count(3)->createdBy($admin)->create();
        $withThreeRows = $count();

        Announcement::factory()->count(6)->createdBy($admin)->create();
        $withNineRows = $count();

        // Assert
        $this->assertSame(
            $withThreeRows,
            $withNineRows,
            "行数を 3 → 9 に増やしたらクエリが {$withThreeRows} → {$withNineRows} 本に増えました。".
            'IndexAction の with() が外れていないか確認してください。',
        );
    }

    /** 一覧を 1 回開くあいだに走った SQL の本数を数える */
    private function countQueriesWhileOpeningIndex(User $admin): int
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($admin)->get(route('admin.announcements.index'))->assertOk();

        return $queries;
    }
}
