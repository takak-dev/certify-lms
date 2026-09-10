<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * 通知(DatabaseNotification)に対する認可ポリシー。
 *
 * 判定はひとつだけ——「その通知の宛先が本人かどうか」。ロールは見ない
 * (受講生・コーチ・管理者のいずれも、自分宛の通知だけを操作できる)。
 *
 * 対象モデルは Laravel が vendor 配下に持つ DatabaseNotification。
 * app/Models/ に無いモデルでも、AuthServiceProvider の $policies に書けば紐づけられる。
 */
class NotificationPolicy
{
    /**
     * 既読にできるのは宛先本人だけ。
     *
     * `notifiable_id` は通知の宛先の主キー(= users.id)。
     * `notifiable_type` も併せて見るのは、将来 User 以外にも通知を送るようになったとき、
     * 別テーブルの同じ id 値と取り違えないようにするため。
     * `getMorphClass()` はモデル名の文字列(既定では 'App\Models\User')を返す。
     */
    public function markAsRead(User $auth, DatabaseNotification $notification): bool
    {
        return $notification->notifiable_type === $auth->getMorphClass()
            && $notification->notifiable_id === $auth->id;
    }
}
