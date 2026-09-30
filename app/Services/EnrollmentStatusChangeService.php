<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentStatusLog;
use App\Models\User;

/**
 * Enrollment 状態遷移の監査ログ(`EnrollmentStatusLog`)を INSERT する Service。
 *
 * 呼出側 Action がトランザクション内で recordStatusChange() を呼ぶ前提。本 Service 自体は
 * DB::transaction() を持たない(`backend-services.md` の規約準拠、ステートレス。行うのは INSERT と、
 * 管理者ダッシュボードの集計キャッシュを確定後に消す予約だけ)。
 *
 * `final` 不採用: Mockery で recordStatusChange を mock してトランザクション原子性の rollback 検証を
 * Action テストで行う可能性があるため(`UserStatusChangeService` と同じ判断軸)。
 *
 * 受講状態が変わる経路(新規登録 / 合格 / 不合格 / 再開)はすべてここを通るため、管理者ダッシュボードの
 * 集計キャッシュもここで消す(T-A-06 / decisions #281)。消すのは確定後なので、呼出側のトランザクションが
 * ロールバックされたら消えない(decisions #282)。
 * ⚠️ 受講解除(ソフトデリート)はここを通らないので、Enrollment\DestroyAction が別に消している(decisions #215)。
 */
final class EnrollmentStatusChangeService
{
    public function __construct(
        private readonly EnrollmentStatsService $stats,
    ) {}

    /**
     * @param Enrollment $enrollment 状態遷移する対象 Enrollment
     * @param ?EnrollmentStatus $fromStatus 遷移前ステータス(初回登録時のみ null、それ以降は必須)
     * @param EnrollmentStatus $toStatus 遷移後ステータス
     * @param ?User $changedBy 操作者(null はシステム自動 = Schedule Command 等)
     * @param ?string $reason 変更理由(任意、UI 表示用)
     */
    public function recordStatusChange(
        Enrollment $enrollment,
        ?EnrollmentStatus $fromStatus,
        EnrollmentStatus $toStatus,
        ?User $changedBy,
        ?string $reason = null,
    ): EnrollmentStatusLog {
        $log = $enrollment->statusLogs()->create([
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus->value,
            'changed_by_user_id' => $changedBy?->id,
            'changed_reason' => $reason,
            'changed_at' => now(),
        ]);

        // 状態が変わったので、管理者ダッシュボードの集計(受講中 / 合格 / 不合格の件数・修了率)が古くなる。
        // 予約するだけで、実際に消えるのは呼出側のトランザクションが確定したとき。
        $this->stats->forgetAdminDashboardCache();

        return $log;
    }
}
