<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanNotDeletableException;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * 受講プランを物理削除するユースケース。plans に softDeletes は無い(取り消せない)。
 *
 * 削除できるのは「下書き かつ 受講者0名 かつ プラン履歴0件」のときだけ。
 * 画面(plan/management/show.blade.php:86)は削除ボタンを状態で出し分けて「いない」ので、
 * 下書き以外でもボタンが出る。つまりブラウザ側の制御が無く、ここが唯一の防御。
 * (面談パックの同じ位置には @if があるが、プランの支給 Blade には無い)
 *
 * ⚠️ 受講者の判定に withTrashed() が必須。退会は plan_id を消さずに論理削除するだけなので
 * (app/Services/UserWithdrawalService.php:28-35)、素の $plan->users() からは見えない。
 * 見えないまま削除すると users.plan_id の外部キー(restrictOnDelete)に触れて 500 になる。
 *
 * ⚠️ 判定の順番は「取り消せない障害を先に」——受講者 → 履歴 → 状態(decisions #127)。
 * 複数に違反していると最初の1つだけが管理者に伝わるので、順番がそのまま案内の優先順位になる。
 * 順番は DestroyTest::test_user_guard_is_reported_before_status_guard が固定している。
 *
 * 手本: app/UseCases/CertificationCategory/DestroyAction.php
 */
final class DestroyAction
{
    /**
     * @throws PlanNotDeletableException 下書きでない / 受講者が残っている / プラン履歴が残っている
     */
    public function __invoke(Plan $plan): void
    {
        // 取り消せない障害を先に見る(decisions #127)。状態の判定だけはクエリ 0 本だが、
        // 削除は頻度の低い操作なので、クエリ本数より案内の正しさを優先する
        if ($plan->users()->withTrashed()->exists()) {
            throw PlanNotDeletableException::forUsers();
        }

        if ($plan->userPlanLogs()->exists()) {
            throw PlanNotDeletableException::forLogs();
        }

        if ($plan->status !== PlanStatus::Draft) {
            throw PlanNotDeletableException::forStatus();
        }

        DB::transaction(fn () => $plan->delete());
    }
}
