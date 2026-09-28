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
 *
 * S-A-05 の通知 JSON API(app/Http/Controllers/Api/NotificationController.php の markAsRead)も
 * 同じ Action を呼ぶ。こちらはリダイレクトせず、返した URL を JSON で渡し、JS が location.href で移動する。
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
        // `/\example.com` と、タブ・改行を挟んだ `/<TAB>/example.com` も弾く。ブラウザは `\` を `/` に
        // 読み替え、タブ・改行を取り除くので、どちらも `//example.com` と同じ扱いになる
        // (2026-09-29 ブラウザで実測。S-A-05 の API はこの値を JS の location.href にそのまま渡すため、
        // web 版の redirect() のような自分のホストへの固定が効かない)。
        // 正規表現の意味: 先頭が `/` で、2 文字目が `/` でも `\` でもない(または 1 文字だけ)。
        // `\\\\` と 4 つ重ねているのは 2 段階のエスケープのため: PHP の文字列で `\\` になり、
        // 正規表現の中でさらに `\` 1 文字を表す
        if (! is_string($url)
            || preg_match('#^/(?![/\\\\])#', $url) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            // `url` を持たない通知(運営お知らせ)は通知詳細ページで全文を読む(decisions #42 / #83)。
            // S-B-04 の時点では詳細ページのルートが無く一覧へ戻していたが、S-B-08 で本来の行き先に差し替えた
            return route('notifications.show', $notification);
        }

        return $url;
    }
}
