<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 自分宛の通知一覧を取得するユースケース。
 *
 * 通知は Laravel 標準の `notifications` テーブルに入る。`User` が Notifiable トレイトを
 * use しているため(`app/Models/User.php:29`)、`$user->notifications()` でそのユーザー宛だけを引ける。
 * 他人の通知が混ざる余地が無いので、ここに追加の絞り込みは要らない。
 */
final class IndexAction
{
    /**
     * @param string|null $tab 'unread' なら未読のみ。それ以外(null / 'all')は全件
     */
    public function __invoke(User $viewer, ?string $tab = null, int $perPage = 20): LengthAwarePaginator
    {
        // notifications() は MorphMany。この時点で ->latest() が効いているため新着順になる
        // (vendor/laravel/framework/src/Illuminate/Notifications/HasDatabaseNotifications.php:14)
        $query = $viewer->notifications();

        if ($tab === 'unread') {
            // unread() は DatabaseNotification のスコープ。中身は whereNull('read_at')
            // (vendor/laravel/framework/src/Illuminate/Notifications/DatabaseNotification.php:119-122)
            $query->unread();
        }

        // withQueryString() でページ送りのリンクに ?tab=unread を引き継ぐ。
        // これが無いと 2 ページ目に進んだ瞬間タブが「全件」に戻る
        return $query
            ->paginate($perPage)
            ->withQueryString();
    }
}
