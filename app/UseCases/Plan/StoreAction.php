<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 受講プランを新規作成するユースケース。
 * status=draft で INSERT し、操作した admin を created_by / updated_by の両方に記録する。
 *
 * 手本: app/UseCases/MeetingPack/StoreAction.php
 */
final class StoreAction
{
    /**
     * @param array{name: string, description?: ?string, duration_days: int, default_meeting_quota: int, sort_order?: ?int} $validated
     */
    public function __invoke(User $admin, array $validated): Plan
    {
        return DB::transaction(fn () => Plan::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'duration_days' => $validated['duration_days'],
            'default_meeting_quota' => $validated['default_meeting_quota'],
            // 未入力なら 0。DB の default(0) に任せず明示するのは先例があるため
            // (app/UseCases/CertificationCategory/StoreAction.php:23)
            'sort_order' => $validated['sort_order'] ?? 0,
            // 新規は必ず下書き。画面から状態を選ばせない(原典「新規作成: 初期状態は下書きで作成」)
            'status' => PlanStatus::Draft->value,
            // 両方 NOT NULL。作成時点では作成者と最終更新者が同じ
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]));
    }
}
