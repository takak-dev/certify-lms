<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 面談がキャンセルされたことを相手方に知らせる通知。
 *
 * 宛先は「キャンセルした本人以外の当事者」。受講生がキャンセルすればコーチへ、
 * コーチがキャンセルすれば受講生へ届く。キャンセル確認画面が
 * 「相手方に通知メールが届きます」と明記している
 * (resources/views/meeting/_modals/cancel-confirm.blade.php:21。decisions #77)。
 *
 * ⭐ キューで送る(T-A-05)。送信の本体は worker(`sail artisan queue:work`)が行い、
 * 宛先 × チャネル(database / mail)ごとに別々のジョブになる(Illuminate/Notifications/NotificationSender.php:188-235)。
 * 失敗したら待ってやり直す(RetriesWithBackoff / decisions #271)。
 */
class MeetingCanceledNotification extends Notification implements ShouldQueue
{
    // 配信対象の制御(decisions #76)。受講中でない人・管理者には送らない
    use DeliversToActiveUsersOnly;

    // キューに積むための道具一式(接続・キュー名・遅延の指定)。NotificationSender が
    // $notification->connection などを直接読む(NotificationSender.php:202-214)ため、ShouldQueue と組で必須
    use Queueable;

    // 失敗したら待ってやり直す(最大 4 回・10 秒 → 60 秒 → 300 秒。decisions #271)
    use RetriesWithBackoff;

    /** メール件名の接頭辞。件名は「接頭辞 + 通知タイトル」で統一する（decisions #80） */
    private const SUBJECT_PREFIX = '[Certify LMS] ';

    public function __construct(private readonly Meeting $meeting) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'notification_type' => NotificationType::MeetingCanceled->value,
            'title' => $this->title(),
            'message' => $this->scheduledAt().'／'.$this->canceledBy().' がキャンセルしました',
            'url' => route('meetings.show', $this->meeting->id, false),
            // 用途は MeetingReservedNotification と同じ(decisions #36)
            'meeting_id' => $this->meeting->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(self::SUBJECT_PREFIX.$this->title())
            ->greeting($notifiable->name.' 様')
            ->line($this->scheduledAt().' の面談が'.$this->canceledBy().'によりキャンセルされました。')
            ->action('面談の詳細を見る', route('meetings.show', $this->meeting->id))
            ->salutation('Certify LMS 運営チーム');
    }

    private function scheduledAt(): string
    {
        return $this->meeting->scheduled_at->translatedFormat('Y年n月j日 (D) H:i');
    }

    /**
     * キャンセルした人の氏名。退会済みでも氏名は残す方針(decisions #46 / #67)に合わせ、
     * 取得できないときだけ「相手方」と表示する。
     */
    private function canceledBy(): string
    {
        return $this->meeting->canceledBy?->name ?? '相手方';
    }

    /**
     * 一覧の見出しに出す文言。メールの件名にも同じものを使う。
     *
     * 2 箇所で別々に書くと片方だけ直したときに食い違う。実際に一度食い違い、
     * テストがその食い違いを「正」として固定していた（decisions #80 は
     * 「件名は `[Certify LMS] {通知タイトル}`」と定めている）。
     */
    private function title(): string
    {
        return '面談がキャンセルされました';
    }
}
