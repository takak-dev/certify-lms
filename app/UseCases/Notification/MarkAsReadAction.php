<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use Illuminate\Notifications\DatabaseNotification;

/**
 * 通知を 1 件既読にし、遷移先の URL を返すユースケース。
 *
 * 一覧の行全体が「既読化フォームの送信ボタン」になっているため
 * (resources/views/notifications/_partials/notification-row.blade.php:28-35)、
 * クリック = POST → 既読化 → 業務画面へリダイレクト という動線になる。
 * 遷移先は通知データの `url` に持たせる(decisions #42)。
 */
final class MarkAsReadAction
{
    public function __invoke(DatabaseNotification $notification): string
    {
        // markAsRead() は Laravel が用意しているメソッド。read_at が null のときだけ書き込むので、
        // 既読の通知をもう一度クリックしても最初に読んだ時刻が上書きされない
        // (vendor/laravel/framework/src/Illuminate/Notifications/DatabaseNotification.php:63-68)
        $notification->markAsRead();

        $data = is_array($notification->data) ? $notification->data : [];
        $url = $data['url'] ?? null;

        // 遷移先はアプリ内の相対パスだけを許す。
        // `http://…` や `//example.com` を弾くのは、通知を外部サイトへの踏み台にしないため
        // (オープンリダイレクト。`//` で始まる URL はブラウザが外部ホストとして解釈する)。
        if (! is_string($url) || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            // `url` を持たない通知(運営お知らせ)は通知詳細ページで全文を読む(decisions #42 / #83)。
            // S-B-04 の時点では詳細ページのルートが無く一覧へ戻していたが、S-B-08 で本来の行き先に差し替えた
            return route('notifications.show', $notification);
        }

        return $url;
    }
}
