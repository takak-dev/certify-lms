<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Meeting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 面談が予約されたことを担当コーチに知らせる通知。
 *
 * 宛先はコーチのみ。予約した受講生本人には送らない——予約画面が
 * 「予約完了後、コーチに通知メールが届きます」と明記している
 * (resources/views/meeting/create.blade.php:158。decisions #77)。
 *
 * ⚠️ ShouldQueue は付けない(キュー化は T-A-05 の担当)。
 */
class MeetingReservedNotification extends Notification
{
    // 配信対象の制御(decisions #76)。受講中でない人・管理者には送らない
    use DeliversToActiveUsersOnly;

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
            'notification_type' => NotificationType::MeetingReserved->value,
            'title' => $this->title(),
            'message' => $this->scheduledAt().'／'.$this->meeting->student->name.' さん',
            'url' => route('meetings.show', $this->meeting->id, false),
            // S-B-09 が面談リマインダーの重複配信を検査するとき、この列を JSON path で引く
            // (decisions #36。専用ログテーブルを作らない方式のため通知データが台帳を兼ねる)
            'meeting_id' => $this->meeting->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(self::SUBJECT_PREFIX.$this->title())
            ->greeting($notifiable->name.' 様')
            ->line($this->meeting->student->name.' さんから面談の予約が入りました。')
            ->line('日時: '.$this->scheduledAt())
            ->line('相談内容: '.$this->meeting->topic)
            ->action('面談の詳細を見る', route('meetings.show', $this->meeting->id))
            ->salutation('Certify LMS 運営チーム');
    }

    private function scheduledAt(): string
    {
        // 曜日つきの和文表記は既存 Blade と揃える(元号ではなく西暦)(meeting/_modals/cancel-confirm.blade.php:18)
        return $this->meeting->scheduled_at->translatedFormat('Y年n月j日 (D) H:i');
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
        return '面談が予約されました';
    }
}
