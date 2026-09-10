<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\ChatMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * チャットに新しいメッセージが届いたことを知らせる通知。
 *
 * 宛先はルームの他のメンバー(送信者自身は除く)。誰に送るかは発火側が決める。
 *
 * ⚠️ ShouldQueue は付けない(キュー化は T-A-05 の担当)。
 */
class ChatMessageReceivedNotification extends Notification
{
    // 配信対象の制御(decisions #76)。受講中でない人・管理者には送らない
    use DeliversToActiveUsersOnly;

    /** メール件名の接頭辞。件名は「接頭辞 + 通知タイトル」で統一する（decisions #80） */
    private const SUBJECT_PREFIX = '[Certify LMS] ';

    public function __construct(private readonly ChatMessage $message) {}

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
            'notification_type' => NotificationType::ChatMessageReceived->value,
            'title' => $this->title(),
            'message' => $this->summary(),
            'url' => route('chat.show', $this->message->chat_room_id, false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(self::SUBJECT_PREFIX.$this->title())
            ->greeting($notifiable->name.' 様')
            ->line($this->message->sender->name.' さんからメッセージが届きました。')
            ->line($this->summary())
            ->action('メッセージを見る', route('chat.show', $this->message->chat_room_id))
            ->salutation('Certify LMS 運営チーム');
    }

    private function summary(): string
    {
        return Str::limit($this->message->body, 100);
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
        return $this->message->sender->name.' さんからメッセージが届きました';
    }
}
