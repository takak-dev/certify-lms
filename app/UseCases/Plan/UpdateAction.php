<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 受講プランの基本情報を更新するユースケース。
 * status は本 Action では更新せず、状態遷移用の Action(Publish / Archive / Unarchive)に責務を分ける。
 * created_by_user_id も触らない(作った人は変わらない)。
 *
 * ⚠️ 受講期間(duration_days)と初期付与面談回数(default_meeting_quota)を変えても、
 * すでに契約している受講生の plan_expires_at / max_meetings は変わらない。
 * プラン変更を既存受講生へ反映する処理は本チケットのスコープ外
 * (原典 スコープ外「受講生招待時のプラン指定 / プラン情報の初期化」「プラン延長」)。
 *
 * 手本: app/UseCases/MeetingPack/UpdateAction.php
 */
final class UpdateAction
{
    /**
     * @param array{name: string, description?: ?string, duration_days: int, default_meeting_quota: int, sort_order?: ?int} $validated
     */
    public function __invoke(Plan $plan, User $admin, array $validated): Plan
    {
        return DB::transaction(function () use ($plan, $admin, $validated) {
            $plan->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'duration_days' => $validated['duration_days'],
                'default_meeting_quota' => $validated['default_meeting_quota'],
                'sort_order' => $validated['sort_order'] ?? 0,
                // 最終更新者だけ書き換える
                'updated_by_user_id' => $admin->id,
            ]);

            // fresh() は DB から読み直した新しいインスタンスを返す。更新後の値を確実に画面へ渡すため
            return $plan->fresh();
        });
    }
}
