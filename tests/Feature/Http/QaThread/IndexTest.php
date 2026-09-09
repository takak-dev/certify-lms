<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 質問掲示板の一覧（GET /qa-board、GET /admin/qa-board）の検証。
 *
 * ここで守りたいのは「誰にどの行が見えるか」。表示の細部ではなく可視範囲を固定する。
 * B-B-15（コーチの一覧に担当外が出る）と同じ型のバグを、スコープの単体ではなく
 * HTTP 経由で検知したいのでフィーチャーテストに置く。
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_sees_only_threads_of_published_certifications(): void
    {
        // Arrange: 公開中と公開停止（アーカイブ済）の資格を1つずつ用意し、それぞれにスレッドを置く
        $published = Certification::factory()->published()->create();
        $archived = Certification::factory()->archived()->create();
        $student = User::factory()->student()->create();

        $visible = QaThread::factory()->forCertification($published)->create(['title' => '見えるはずの質問']);
        $hidden = QaThread::factory()->forCertification($archived)->create(['title' => '見えてはいけない質問']);

        // Act
        $response = $this->actingAs($student)->get(route('qa-board.index'));

        // Assert: 公開中の資格のスレッドだけが一覧に含まれる
        $response->assertOk();
        $response->assertViewHas('threads', function ($threads) use ($visible, $hidden) {
            $ids = $threads->pluck('id')->all();

            return in_array($visible->id, $ids, true) && ! in_array($hidden->id, $ids, true);
        });
    }

    public function test_coach_sees_only_threads_of_assigned_certifications(): void
    {
        // Arrange: 担当する資格と、担当しない資格を用意する（どちらも公開中）
        $assigned = Certification::factory()->published()->create();
        $notAssigned = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        // 中間テーブルは ULID 主キーと割当メタ情報を持つため、attach 時に明示する
        // （手本: tests/Unit/Policies/MockExamPolicyTest.php:43-47）
        $assigned->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $visible = QaThread::factory()->forCertification($assigned)->create();
        $hidden = QaThread::factory()->forCertification($notAssigned)->create();

        // Act
        $response = $this->actingAs($coach)->get(route('qa-board.index'));

        // Assert: 担当資格のスレッドだけ。担当外は「一覧に出さない」（decisions #40）
        $response->assertOk();
        $response->assertViewHas('threads', function ($threads) use ($visible, $hidden) {
            $ids = $threads->pluck('id')->all();

            return in_array($visible->id, $ids, true) && ! in_array($hidden->id, $ids, true);
        });
    }

    public function test_admin_sees_threads_of_unpublished_certifications_on_moderation_screen(): void
    {
        // Arrange: 公開停止（アーカイブ済）資格のスレッド。管理者だけが見られる
        $archived = Certification::factory()->archived()->create();
        $thread = QaThread::factory()->forCertification($archived)->create();
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.qa-board.index'));

        // Assert
        $response->assertOk();
        $response->assertViewHas('threads', fn ($threads) => in_array($thread->id, $threads->pluck('id')->all(), true));
    }

    public function test_admin_cannot_open_public_board_and_student_cannot_open_moderation_screen(): void
    {
        // Arrange: 公開画面は受講生 / コーチ専用、モデレーション画面は管理者専用（原典の HTTP 表）
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();

        // Act & Assert: 入口のミドルウェアで弾かれる（Policy まで到達しない）
        $this->actingAs($admin)->get(route('qa-board.index'))->assertForbidden();
        $this->actingAs($student)->get(route('admin.qa-board.index'))->assertForbidden();
    }

    public function test_graduated_student_cannot_access_board(): void
    {
        // Arrange: 修了したユーザーはプラン機能に入れない（原典「受講中の受講生・コーチのみアクセスできる」）
        $graduated = User::factory()->student()->graduated()->create();

        // Act & Assert: active-learning ミドルウェアが 403 にする
        $this->actingAs($graduated)->get(route('qa-board.index'))->assertForbidden();
    }

    public function test_filter_chips_match_what_the_viewer_can_actually_see(): void
    {
        // Arrange: 絞り込みチップに担当外の資格が並ぶと、押しても 0 件のチップができてしまう。
        // 一覧に出るスレッドの範囲とチップの範囲を揃える（decisions #40 と同じ考え方）
        $assigned = Certification::factory()->published()->create(['name' => '担当している資格']);
        $notAssigned = Certification::factory()->published()->create(['name' => '担当していない資格']);
        $archived = Certification::factory()->archived()->create(['name' => '公開停止の資格']);

        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        $assigned->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        // Act & Assert: コーチは担当資格だけ
        $this->actingAs($coach)->get(route('qa-board.index'))
            ->assertViewHas('certifications', function ($certifications) {
                $names = $certifications->pluck('name')->all();

                return $names === ['担当している資格'];
            });

        // Act & Assert: 受講生は公開中すべて（受講登録は前提にしない）
        $this->actingAs($student)->get(route('qa-board.index'))
            ->assertViewHas('certifications', function ($certifications) {
                $names = $certifications->pluck('name')->all();

                return in_array('担当していない資格', $names, true)
                    && ! in_array('公開停止の資格', $names, true);
            });

        // Act & Assert: 管理者は公開停止中の資格も選べる（index.blade.php:26「全資格(公開停止含む)」）
        $this->actingAs($admin)->get(route('admin.qa-board.index'))
            ->assertViewHas('certifications', fn ($certifications) => in_array('公開停止の資格', $certifications->pluck('name')->all(), true));
    }

    public function test_threads_are_sorted_by_newest_first(): void
    {
        // Arrange: 作成日時をずらした3件。新着順（降順）に並ぶことを確認する
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();

        $old = QaThread::factory()->forCertification($certification)->create(['created_at' => now()->subDays(3)]);
        $newest = QaThread::factory()->forCertification($certification)->create(['created_at' => now()->subHour()]);
        $middle = QaThread::factory()->forCertification($certification)->create(['created_at' => now()->subDay()]);

        // Act
        $response = $this->actingAs($student)->get(route('qa-board.index'));

        // Assert: 新しいものから順に並ぶ
        $response->assertOk();
        $response->assertViewHas('threads', function ($threads) use ($newest, $middle, $old) {
            return $threads->pluck('id')->all() === [$newest->id, $middle->id, $old->id];
        });
    }

    public function test_status_filter_narrows_threads(): void
    {
        // Arrange: 未解決1件・解決済1件
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();

        $unresolved = QaThread::factory()->forCertification($certification)->create();
        $resolved = QaThread::factory()->forCertification($certification)->resolved()->create();

        // Act: 解決済だけに絞り込む（支給 Blade が送る値は 'resolved'）
        $response = $this->actingAs($student)->get(route('qa-board.index', ['status' => 'resolved']));

        // Assert
        $response->assertOk();
        $response->assertViewHas('threads', function ($threads) use ($resolved, $unresolved) {
            $ids = $threads->pluck('id')->all();

            return in_array($resolved->id, $ids, true) && ! in_array($unresolved->id, $ids, true);
        });
    }

    public function test_certification_filter_narrows_threads(): void
    {
        // Arrange: 資格2つにそれぞれスレッドを置く
        $target = Certification::factory()->published()->create();
        $other = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();

        $wanted = QaThread::factory()->forCertification($target)->create();
        $unwanted = QaThread::factory()->forCertification($other)->create();

        // Act
        $response = $this->actingAs($student)->get(route('qa-board.index', ['certification_id' => $target->id]));

        // Assert
        $response->assertOk();
        $response->assertViewHas('threads', function ($threads) use ($wanted, $unwanted) {
            $ids = $threads->pluck('id')->all();

            return in_array($wanted->id, $ids, true) && ! in_array($unwanted->id, $ids, true);
        });
    }

    public function test_keyword_search_targets_body_only(): void
    {
        // Arrange: 検索語を本文だけに持つスレッドと、タイトルだけに持つスレッドを用意する。
        // decisions #63「検索対象は本文のみ」を固定する。タイトルも対象にする実装へ変えると、このテストが落ちる
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();

        $inBody = QaThread::factory()->forCertification($certification)->create([
            'title' => 'ふつうの質問',
            'body' => 'ここに正規化という語を含みます。',
        ]);
        $inTitleOnly = QaThread::factory()->forCertification($certification)->create([
            'title' => '正規化についての質問',
            'body' => 'タイトルにだけ検索語があります。',
        ]);

        // Act
        $response = $this->actingAs($student)->get(route('qa-board.index', ['keyword' => '正規化']));

        // Assert: 本文に含むものだけがヒットする
        $response->assertOk();
        $response->assertViewHas('threads', function ($threads) use ($inBody, $inTitleOnly) {
            $ids = $threads->pluck('id')->all();

            return in_array($inBody->id, $ids, true) && ! in_array($inTitleOnly->id, $ids, true);
        });
    }

    public function test_threads_carry_reply_count_for_the_card_badge(): void
    {
        // Arrange: 一覧カードは回答0件を「未回答」バッジで出し分ける（_thread-card.blade.php:6,8-12）。
        // withCount('replies') が外れると $thread->replies_count が null になり、バッジが常に「未回答」になる
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();

        $thread = QaThread::factory()->forCertification($certification)->create();
        QaReply::factory()->forThread($thread)->count(2)->create();

        // Act
        $response = $this->actingAs($student)->get(route('qa-board.index'));

        // Assert
        $response->assertOk();
        $response->assertViewHas('threads', fn ($threads) => $threads->firstWhere('id', $thread->id)->replies_count === 2);
    }

    public function test_pagination_shows_twenty_threads_per_page(): void
    {
        // Arrange: 21件用意し、1ページ目が20件で切られることを確認する（decisions #64）
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();
        QaThread::factory()->forCertification($certification)->count(21)->create();

        // Act
        $response = $this->actingAs($student)->get(route('qa-board.index'));

        // Assert
        $response->assertOk();
        $response->assertViewHas('threads', fn ($threads) => $threads->count() === 20 && $threads->total() === 21);
    }
}
