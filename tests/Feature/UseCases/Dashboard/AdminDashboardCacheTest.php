<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Dashboard;

use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\EnrollmentStatusChangeService;
use App\UseCases\Dashboard\FetchAdminDashboardAction;
use App\UseCases\Enrollment\DestroyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * 管理者ダッシュボード集計のキャッシュ挙動を検証する Feature テスト。
 * 全体 KPI と資格別修了率の 2 キーそれぞれについて、連続表示で集計が Cache::remember から
 * 再利用されること(重い集計を再実行しない)と、受講状態の遷移(EnrollmentStatusChangeService)で
 * 両キーが無効化され最新値に更新されること(片方の forget 漏れも検出する)を網羅する。
 */
class AdminDashboardCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_kpi_is_served_from_cache_on_second_fetch(): void
    {
        // Arrange: admin + 受講中 2 件、キャッシュは空の状態から始める
        $admin = User::factory()->admin()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        Enrollment::factory()->for($cert)->learning()->count(2)->create();
        Cache::flush();

        // Act: 1 回目で集計してキャッシュ → 無効化経路を通さず DB を直接増やす → クエリ計測しつつ 2 回目
        $first = app(FetchAdminDashboardAction::class)($admin);
        Enrollment::factory()->for($cert)->learning()->count(3)->create();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });
        $second = app(FetchAdminDashboardAction::class)($admin);

        // Assert: 2 回目はキャッシュヒットで重い集計クエリが 1 本も走らず、値も 1 回目のまま(直接 INSERT は反映されない)
        $this->assertSame(2, $first->kpi['learning_count']);
        $this->assertSame(
            2,
            $second->kpi['learning_count'],
            'TTL 内で無効化イベントが無ければ集計はキャッシュから返るはず(直接 INSERT は反映されない)',
        );
        $this->assertSame(
            0,
            $queryCount,
            'キャッシュヒット時は重い集計クエリが 1 本も発行されないはず',
        );
        $this->assertTrue(
            Cache::has(config('dashboard.admin_kpi_cache_key')),
            '管理者 KPI 集計がキャッシュキーに保存されているはず',
        );
    }

    public function test_completion_rate_is_served_from_cache_on_second_fetch(): void
    {
        // Arrange: admin + 受講中 2 件(修了率 0 %)、キャッシュは空の状態から始める
        $admin = User::factory()->admin()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        Enrollment::factory()->for($cert)->learning()->count(2)->create();
        Cache::flush();

        // Act: 1 回目で集計してキャッシュ → 無効化経路を通さず合格者を直接増やす → クエリ計測しつつ 2 回目
        $first = app(FetchAdminDashboardAction::class)($admin);
        Enrollment::factory()->for($cert)->passed()->create();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });
        $second = app(FetchAdminDashboardAction::class)($admin);

        // Assert: 2 回目はキャッシュヒットで集計クエリが走らず、修了率も 1 回目のまま(直接 INSERT は反映されない)
        $firstRate = $first->completionRateByCertification->firstWhere('certification_id', $cert->id)['completion_rate'];
        $secondRate = $second->completionRateByCertification->firstWhere('certification_id', $cert->id)['completion_rate'];

        $this->assertSame(0.0, $firstRate);
        $this->assertSame(
            0.0,
            $secondRate,
            'TTL 内で無効化イベントが無ければ修了率はキャッシュから返るはず(直接 INSERT は反映されない)',
        );
        $this->assertSame(
            0,
            $queryCount,
            'キャッシュヒット時は重い集計クエリが 1 本も発行されないはず',
        );
        $this->assertTrue(
            Cache::has(config('dashboard.admin_completion_rate_cache_key')),
            '資格別修了率の集計がキャッシュキーに保存されているはず',
        );
    }

    public function test_admin_kpi_cache_is_invalidated_on_enrollment_status_change(): void
    {
        // Arrange: admin + 受講中 2 件を 1 度集計してキャッシュさせる
        $admin = User::factory()->admin()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $enrollments = Enrollment::factory()->for($cert)->learning()->count(2)->create();
        Cache::flush();
        app(FetchAdminDashboardAction::class)($admin);

        // Act: 1 件を合格に遷移(状態遷移チョークポイント経由でキャッシュが無効化される)
        $target = $enrollments->first();
        $target->update(['status' => EnrollmentStatus::Passed]);
        app(EnrollmentStatusChangeService::class)->recordStatusChange(
            $target,
            EnrollmentStatus::Learning,
            EnrollmentStatus::Passed,
            $admin,
        );
        $after = app(FetchAdminDashboardAction::class)($admin);

        // Assert: KPI キャッシュが無効化され、最新の集計(learning 1 / passed 1)が返る
        $this->assertSame(
            1,
            $after->kpi['learning_count'],
            '状態遷移後は KPI キャッシュが無効化され、最新の受講中件数が返るはず',
        );
        $this->assertSame(1, $after->kpi['passed_count']);
    }

    public function test_completion_rate_cache_is_invalidated_on_enrollment_status_change(): void
    {
        // Arrange: admin + 受講中 2 件(修了率 0 %)を 1 度集計してキャッシュさせる
        $admin = User::factory()->admin()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $enrollments = Enrollment::factory()->for($cert)->learning()->count(2)->create();
        Cache::flush();
        app(FetchAdminDashboardAction::class)($admin);

        // Act: 1 件を合格に遷移(状態遷移チョークポイント経由でキャッシュが無効化される)
        $target = $enrollments->first();
        $target->update(['status' => EnrollmentStatus::Passed]);
        app(EnrollmentStatusChangeService::class)->recordStatusChange(
            $target,
            EnrollmentStatus::Learning,
            EnrollmentStatus::Passed,
            $admin,
        );
        $after = app(FetchAdminDashboardAction::class)($admin);

        // Assert: 修了率キャッシュも無効化され、最新の修了率(合格 1 / 全 2 = 0.5)が返る
        $afterRate = $after->completionRateByCertification->firstWhere('certification_id', $cert->id)['completion_rate'];
        $this->assertSame(
            0.5,
            $afterRate,
            '状態遷移後は修了率キャッシュも無効化され、最新の修了率(1/2)が返るはず',
        );
    }

    /*
     * ここから下は T-A-06 で追加したテスト。上の 4 本(支給)が確かめていない経路を補う。
     */

    /**
     * 受講解除(ソフトデリート)でも両方のキャッシュが消えること(decisions #215)。
     *
     * 受講解除は状態が変わらず recordStatusChange() を通らないので、上の 2 本では拾えない。
     * DestroyAction に足した forgetAdminDashboardCache() の 1 行を消すと、このテストが赤になる。
     */
    public function test_both_caches_are_invalidated_when_enrollment_is_destroyed(): void
    {
        // Arrange: 受講中 1 件 + 合格 1 件(受講中件数 1 / 修了率 1/2 = 0.5)を 1 度集計してキャッシュさせる。
        //   受講中を 2 件にしないのは、修了率が「0/2 → 0/1」でどちらも 0.0 になり、変化が見えないため。
        //   受講解除できるのは受講中だけ(DestroyAction が合格・不合格を拒否する)なので、合格を 1 件混ぜる。
        $admin = User::factory()->admin()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $learning = Enrollment::factory()->for($cert)->learning()->create();
        Enrollment::factory()->for($cert)->passed()->create();
        Cache::flush();
        app(FetchAdminDashboardAction::class)($admin);

        // Act: 受講中の 1 件を、受講生の画面と同じ Action で受講解除する。
        //   $learning->delete() を直接呼ぶと、DestroyAction に足した 1 行を通らず何も確かめられない。
        app(DestroyAction::class)($learning);
        $after = app(FetchAdminDashboardAction::class)($admin);

        // Assert: 2 本とも消えて、解除後の値(受講中 0 件 / 修了率 1/1 = 1.0)で集計し直されている。
        $this->assertSame(
            0,
            $after->kpi['learning_count'],
            '受講解除後は KPI キャッシュが消え、解除した分が受講中件数から減るはず',
        );
        $afterRate = $after->completionRateByCertification->firstWhere('certification_id', $cert->id)['completion_rate'];
        $this->assertSame(
            1.0,
            $afterRate,
            '受講解除後は修了率キャッシュも消え、解除した分が分母から減るはず',
        );
    }

    /**
     * キャッシュの保存時間が設定値(config/dashboard.php の admin_cache_ttl_seconds)に従うこと。
     * 原典の要件「キャッシュの保存時間(TTL)は設定値で調整できる」を確かめる。
     */
    public function test_cache_expires_after_the_configured_ttl(): void
    {
        // Arrange: 保存時間を既定の 300 秒から 60 秒に変える。
        //   既定値と違う数にしておかないと、「設定を読まず 300 を直書きしている」実装でも緑になってしまう。
        //   freezeTime() で時計を止めるのは、1 回目の保存から travel() までの実時間(数ミリ秒)で
        //   境目の判定がずれないようにするため。
        config(['dashboard.admin_cache_ttl_seconds' => 60]);
        $this->freezeTime();
        $admin = User::factory()->admin()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        Enrollment::factory()->for($cert)->learning()->count(2)->create();
        Cache::flush();
        app(FetchAdminDashboardAction::class)($admin);

        // 消す処理を通らずに 1 件足す(上の支給テストと同じ手)。キャッシュが生きている間は反映されないはず。
        Enrollment::factory()->for($cert)->learning()->create();

        // Act & Assert ①: 59 秒後はまだ保存時間内なので、古い値(2 件)のまま。
        $this->travel(59)->seconds();
        $this->assertSame(
            2,
            app(FetchAdminDashboardAction::class)($admin)->kpi['learning_count'],
            '設定した保存時間(60 秒)の内側では、キャッシュの値が返るはず',
        );

        // Act & Assert ②: さらに 2 秒進めて 61 秒後。期限が切れて集計し直され、足した 1 件が見える(3 件)。
        $this->travel(2)->seconds();
        $this->assertSame(
            3,
            app(FetchAdminDashboardAction::class)($admin)->kpi['learning_count'],
            '設定した保存時間(60 秒)を過ぎたら、集計し直して最新の値が返るはず',
        );
    }

    /**
     * 状態遷移がロールバックされたら、キャッシュは消えないこと(decisions #282)。
     *
     * 削除はトランザクションの確定後(DB::afterCommit)に予約しているので、取り消されたら予約も消える。
     * forgetAdminDashboardCache() の afterCommit を外して「その場で消す」形にすると、このテストが赤になる。
     */
    public function test_caches_are_kept_when_status_change_is_rolled_back(): void
    {
        // Arrange: 受講中 2 件を 1 度集計してキャッシュさせる。
        //   件数に意味は無い(上の支給テストと同じ形に揃えただけ)。確かめるのはキャッシュのキーが残るかどうかで、
        //   集計の値は見ないため。
        $admin = User::factory()->admin()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $enrollments = Enrollment::factory()->for($cert)->learning()->count(2)->create();
        Cache::flush();
        app(FetchAdminDashboardAction::class)($admin);

        // Act: FailAction などと同じく、トランザクションの中で状態を変えてログを残し、最後に例外で取り消す。
        //   例外はここで受け止める(テスト自体を失敗させないため)。
        $target = $enrollments->first();
        try {
            DB::transaction(function () use ($target, $admin): void {
                $target->update(['status' => EnrollmentStatus::Passed]);
                app(EnrollmentStatusChangeService::class)->recordStatusChange(
                    $target,
                    EnrollmentStatus::Learning,
                    EnrollmentStatus::Passed,
                    $admin,
                );

                throw new RuntimeException('ロールバックさせるための例外');
            });
        } catch (RuntimeException) {
            // 想定どおり。何もしない。
        }

        // Assert: 2 本ともキャッシュに残っている(確定しなかったので、消す予約も取り消された)。
        $this->assertTrue(
            Cache::has(config('dashboard.admin_kpi_cache_key')),
            'ロールバックされたら KPI キャッシュは消えないはず',
        );
        $this->assertTrue(
            Cache::has(config('dashboard.admin_completion_rate_cache_key')),
            'ロールバックされたら修了率キャッシュも消えないはず',
        );
    }
}
