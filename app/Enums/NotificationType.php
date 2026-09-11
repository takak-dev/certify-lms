<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 通知の種別。通知データの `notification_type` キーに入る。
 *
 * ⚠️ 値(文字列)は画面が決めている。変更するとアイコンが既定のベルに戻る
 * (resources/views/notifications/_partials/notification-row.blade.php:14-21 の match)。
 *
 * 画面の分岐は 7 種類あるが、ここに定義するのは実装済みのものだけ。
 * - `completion_approved` … 実装しない(decisions #41)
 * - `meeting_reminder`    … S-B-09 で追加する
 * 未実装のケースをあらかじめ並べると「実装済み」と読めてしまうため、必要になった時点で足す。
 *
 * 📌 `app/Enums/` の他の Enum は全て `label()` を持つが、この Enum には置かない。
 *    画面に出るのはアイコンだけで(notification-row.blade.php:14-21 の match)、
 *    種別名を日本語で表示する箇所が存在しないため。表示が必要になった時点で足す。
 */
enum NotificationType: string
{
    case ChatMessageReceived = 'chat_message_received';
    case QaReplyReceived = 'qa_reply_received';
    case MeetingReserved = 'meeting_reserved';
    case MeetingCanceled = 'meeting_canceled';

    /** 管理者からの一斉お知らせ(S-B-08)。業務イベントではなく運営からの能動的な連絡 */
    case AdminAnnouncement = 'admin_announcement';
}
