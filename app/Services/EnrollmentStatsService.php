<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnrollmentStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Enrollment 集計を提供する Service。admin ダッシュボード KPI で利用される。
 *
 * 全体 KPI(adminKpi)と資格別修了率(completionRateByCertification)は全 enrollment を走査する重い集計。
 * そのため 2 つとも結果をキャッシュし、連続表示では再計算しない(T-A-06 / decisions #279)。
 * キーと保存時間は config/dashboard.php。
 *
 * 集計対象は SoftDelete 除外。paused 集計は採用しない(3 値モデル)。
 * 受講生ダッシュボードの Action / Controller テストで Mockery 経由 mock するため `final` は付けない。
 */
class EnrollmentStatsService
{
    /**
     * 全体 KPI(learning / passed / failed 件数 + 資格別内訳)を返す。
     *
     * @return array{learning_count: int, passed_count: int, failed_count: int, total: int, by_certification: array<int, array{certification_id: string, certification_name: string, learning: int, passed: int, failed: int, total: int}>}
     */
    public function adminKpi(): array
    {
        // Cache::remember(キー, 秒数, 関数):
        //   キーに値があればそれを返す(関数は呼ばれない = クエリが 1 本も飛ばない)。
        //   無ければ関数を実行し、結果を保存してから返す。
        //   関数が例外を投げたときは何も保存されない(次の表示で集計し直す)。
        return Cache::remember(
            config('dashboard.admin_kpi_cache_key'),
            config('dashboard.admin_cache_ttl_seconds'),
            fn (): array => $this->calculateAdminKpi(),
        );
    }

    /**
     * adminKpi() の集計本体(キャッシュを通さない)。
     *
     * @return array{learning_count: int, passed_count: int, failed_count: int, total: int, by_certification: array<int, array{certification_id: string, certification_name: string, learning: int, passed: int, failed: int, total: int}>}
     */
    private function calculateAdminKpi(): array
    {
        $counts = DB::table('enrollments')
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        $learning = (int) ($counts[EnrollmentStatus::Learning->value] ?? 0);
        $passed = (int) ($counts[EnrollmentStatus::Passed->value] ?? 0);
        $failed = (int) ($counts[EnrollmentStatus::Failed->value] ?? 0);

        return [
            'learning_count' => $learning,
            'passed_count' => $passed,
            'failed_count' => $failed,
            'total' => $learning + $passed + $failed,
            'by_certification' => $this->byCertification(),
        ];
    }

    /**
     * 資格別の受講生数(status 別の内訳付き)。
     *
     * @return array<string, array{learning: int, passed: int, failed: int}> キーは certification_id
     */
    public function perCertification(): array
    {
        $rows = DB::table('enrollments')
            ->whereNull('deleted_at')
            ->selectRaw('certification_id, status, COUNT(*) as cnt')
            ->groupBy('certification_id', 'status')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $certId = (string) $row->certification_id;
            $result[$certId] ??= ['learning' => 0, 'passed' => 0, 'failed' => 0];
            $result[$certId][(string) $row->status] = (int) $row->cnt;
        }

        return $result;
    }

    /**
     * 資格別の修了率(passed / 全件)を Collection で返す。
     * 0 件の資格は除外する(0 % 表示は意味がないため)。
     * 一覧は受講生数(total)の多い順、上位 10 件まで。
     *
     * @return Collection<int, array{certification_id: string, certification_name: string, learning: int, passed: int, failed: int, total: int, completion_rate: float}>
     */
    public function completionRateByCertification(): Collection
    {
        // adminKpi() と同じ形。キーだけ別にする(支給テストが 2 本を別々に確かめるため)。
        return Cache::remember(
            config('dashboard.admin_completion_rate_cache_key'),
            config('dashboard.admin_cache_ttl_seconds'),
            fn (): Collection => $this->calculateCompletionRateByCertification(),
        );
    }

    /**
     * completionRateByCertification() の集計本体(キャッシュを通さない)。
     *
     * @return Collection<int, array{certification_id: string, certification_name: string, learning: int, passed: int, failed: int, total: int, completion_rate: float}>
     */
    private function calculateCompletionRateByCertification(): Collection
    {
        return collect($this->byCertification())
            ->filter(fn (array $row): bool => $row['total'] > 0)
            ->map(function (array $row): array {
                $row['completion_rate'] = round($row['passed'] / $row['total'], 4);

                return $row;
            })
            ->sortByDesc('total')
            ->values();
    }

    /**
     * adminKpi() と completionRateByCertification() のキャッシュを 2 本とも消す。
     * 受講状態の遷移(EnrollmentStatusChangeService::recordStatusChange())と受講解除から呼ぶ(decisions #281)。
     *
     * 消すのはトランザクションの確定後(decisions #282)。確定前に消すと、確定までの間に開かれた
     * ダッシュボードが「まだ変わっていない値」で集計し直してキャッシュに入れ、それが保存時間いっぱい残る。
     * DB::afterCommit(関数) は、トランザクションの中なら確定まで待ち(ロールバックなら実行しない)、
     * 外ならその場で実行する。
     *
     * ⚠️ 確定後に例外が出ると、状態の変更は保存済みなのに 500 が返る。消し損ねても
     *    保存時間が切れれば最新値に戻るので、握りつぶして report に回す
     *    (手本: app/UseCases/User/DestroyAvatarAction.php の deleteAfterCommit())。
     */
    public function forgetAdminDashboardCache(): void
    {
        DB::afterCommit(function (): void {
            try {
                Cache::forget(config('dashboard.admin_kpi_cache_key'));
                Cache::forget(config('dashboard.admin_completion_rate_cache_key'));
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * 資格別の集計を「資格 ID + 名前 + status 別件数」の配列で返す内部ヘルパー。
     *
     * @return array<int, array{certification_id: string, certification_name: string, learning: int, passed: int, failed: int, total: int}>
     */
    private function byCertification(): array
    {
        $rows = DB::table('enrollments')
            ->join('certifications', 'enrollments.certification_id', '=', 'certifications.id')
            ->whereNull('enrollments.deleted_at')
            ->selectRaw('enrollments.certification_id, certifications.name as certification_name, enrollments.status, COUNT(*) as cnt')
            ->groupBy('enrollments.certification_id', 'certifications.name', 'enrollments.status')
            ->get();

        $byCertification = [];
        foreach ($rows as $row) {
            $certId = (string) $row->certification_id;
            $byCertification[$certId] ??= [
                'certification_id' => $certId,
                'certification_name' => (string) $row->certification_name,
                'learning' => 0,
                'passed' => 0,
                'failed' => 0,
                'total' => 0,
            ];
            $byCertification[$certId][(string) $row->status] = (int) $row->cnt;
            $byCertification[$certId]['total'] += (int) $row->cnt;
        }

        $list = array_values($byCertification);
        usort($list, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $list;
    }
}
