<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\ChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * チャットに新しいメッセージが届いたことを知らせる通知。
 *
 * 宛先はルームの他のメンバー(送信者自身は除く)。誰に送るかは発火側が決める。
 *
 * ⭐ キューで送る(T-A-05)。送信の本体は worker(`sail artisan queue:work`)が行い、
 * 宛先 × チャネル(database / mail)ごとに別々のジョブになる(Illuminate/Notifications/NotificationSender.php:188-235)。
 * 失敗したら待ってやり直す(RetriesWithBackoff / decisions #271)。
 */
class ChatMessageReceivedNotification extends Notification implements ShouldQueue
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
