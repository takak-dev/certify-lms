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

    /**
     * 予約済み面談の事前リマインダー(S-B-09)。前日 18:00 と開始 1 時間前の 2 回送る。
     *
     * ⚠️ 2 つのタイミングを種別で分けない。画面がこの値でアイコンを決めており
     *    (notification-row.blade.php:18)、値を割ると既定のベルに戻る。
     *    どちらのタイミングかは通知データの `reminder_window` が持つ(decisions #104)。
     */
    case MeetingReminder = 'meeting_reminder';
}
