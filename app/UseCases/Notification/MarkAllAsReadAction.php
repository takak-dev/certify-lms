<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;

/**
 * 自分宛の未読通知をまとめて既読にするユースケース。
 *
 * 未読が大量に残っているとき個別クリックでは追いつかない、というのが要件の理由
 * (チケット原典のユーザーストーリー)。
 */
final class MarkAllAsReadAction
{
    /**
     * @return int 既読にした件数
     */
    public function __invoke(User $user): int
    {
        // 1 クエリで一括更新する。
        // $user->unreadNotifications->markAsRead() でも結果は同じだが、
        // そちらは 1 件ずつ save() するため未読が多いほどクエリが増える
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }
}
