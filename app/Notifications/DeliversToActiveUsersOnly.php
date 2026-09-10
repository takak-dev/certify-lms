<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;

/**
 * 配信対象の制御(decisions #76)を通知クラスに与えるトレイト。
 *
 * ⚠️ これを use した通知は、`notify()` を呼んでも**送られないことがある**。
 * 受け取ってよい相手かどうかを送信直前に判定し、対象外なら黙って捨てる。
 *
 * Laravel は通知クラスに `shouldSend()` があると、チャネルごとの送信直前に呼ぶ
 * (vendor/laravel/framework/src/Illuminate/Notifications/NotificationSender.php:163-168)。
 * false を返すとそのチャネルへの送信が止まる。database / mail の両方で呼ばれるため、
 * 対象外のユーザーには通知行もメールも残らない。
 *
 * 判定の中身は User::canReceiveNotifications() に置いてある。ルールが変わったらそこだけ直す。
 */
trait DeliversToActiveUsersOnly
{
    public function shouldSend(object $notifiable, string $channel): bool
    {
        // User 以外(将来ほかのモデルに通知を送るようになった場合)は判定対象外として通す
        if (! $notifiable instanceof User) {
            return true;
        }

        return $notifiable->canReceiveNotifications();
    }
}
