<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックを新規作成するユースケース。
 * status=draft で INSERT し、操作した admin を created_by / updated_by の両方に記録する。
 *
 * 手本: app/UseCases/Certification/StoreAction.php
 */
final class StoreAction
{
    /**
     * @param array{name: string, description?: ?string, meeting_count: int, price: int, stripe_price_id?: ?string, sort_order?: ?int} $validated
     */
    public function __invoke(User $admin, array $validated): MeetingPack
    {
        return DB::transaction(fn () => MeetingPack::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'meeting_count' => $validated['meeting_count'],
            'price' => $validated['price'],
            'stripe_price_id' => $validated['stripe_price_id'] ?? null,
            // 未入力なら 0。DB の default(0) に任せず明示するのは先例があるため
            // (app/UseCases/CertificationCategory/StoreAction.php:23)
            'sort_order' => $validated['sort_order'] ?? 0,
            // 新規は必ず下書き。画面から状態を選ばせない(原典「新規作成(初期状態は下書き)」)
            'status' => MeetingPackStatus::Draft->value,
            // 両方 NOT NULL。作成時点では作成者と最終更新者が同じ
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]));
    }
}
