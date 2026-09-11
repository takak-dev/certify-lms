<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Announcement;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * 管理者が配信した運営お知らせを知らせる通知(S-B-08)。
 *
 * 他の 4 種類と違い、業務イベントではなく運営からの能動的な連絡。
 * 宛先は配信対象の解決結果で、このクラスは「何を届けるか」だけを持つ。
 *
 * ⭐ `url` を持たない唯一の通知。遷移先となる業務画面が存在せず、本文そのものが中身のため、
 * 既読化したあとは通知詳細ページへ送られる(decisions #42 / #83。MarkAsReadAction のフォールバック)。
 *
 * ⚠️ ShouldQueue は付けない。メール配信のキュー化は T-A-05 の担当で、
 * ここで付けると T-A-05 の失敗テストが意図せず通り、チケットの境界が壊れる。
 */
class AdminAnnouncementNotification extends Notification
{
    // 配信対象の制御(decisions #76)。受講中でない人・管理者には送らない。
    // 付け忘れると NotificationDeliveryArchitectureTest が落ちる(decisions #82)
    use DeliversToActiveUsersOnly;

    /** メール件名の接頭辞。件名は「接頭辞 + 通知タイトル」で統一する（decisions #80） */
    private const SUBJECT_PREFIX = '[Certify LMS] ';

    public function __construct(private readonly Announcement $announcement) {}

    /**
     * 配信チャネル。アプリ内通知(database)とメール(mail)の 2 つ。
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * アプリ内通知として `notifications.data` に入る中身。
     *
     * キー名は画面が決めている。`title` / `message` は一覧の行
     * (notification-row.blade.php:9-10)、`body` は詳細ページの全文表示
     * (notifications/show.blade.php:15)。
     *
     * `url` は入れない。遷移先の業務画面が無いことがフォールバックの条件になっている
     * (MarkAsReadAction。値が無い → 通知詳細ページへ)。
     *
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'notification_type' => NotificationType::AdminAnnouncement->value,
            'title' => $this->title(),
            'message' => Str::limit($this->announcement->body, 100),
            'body' => $this->announcement->body,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(self::SUBJECT_PREFIX.$this->title())
            ->greeting($notifiable->name.' 様')
            ->line('運営からのお知らせが届きました。');

        // 本文は全文を載せる。他の通知と違い、この通知自体が届けたい中身そのものだから。
        // 空行区切りの段落ごとに line() を呼ぶ(1 回で渡すと改行が潰れて 1 段落になる)
        foreach ($this->paragraphs() as $paragraph) {
            $mail->line($paragraph);
        }

        // 通知詳細ページへ直接送る。ここで参照している $this->id は Laravel が送信の冒頭で採番し
        // (vendor/laravel/framework/src/Illuminate/Notifications/NotificationSender.php:140-141)、
        // database チャネルが作る行の主キーと同じ値になる。実際に notify() を通して一致を確認済み。
        // メールのリンクは相対パスでは開けないため絶対 URL を渡す
        return $mail
            ->action('お知らせを見る', route('notifications.show', $this->id))
            ->salutation('Certify LMS 運営チーム');
    }

    /**
     * 本文を空行で段落に割る。
     *
     * @return array<int, string>
     */
    private function paragraphs(): array
    {
        $parts = preg_split('/\R{2,}/', trim($this->announcement->body)) ?: [];

        return array_values(array_filter($parts, static fn (string $p): bool => trim($p) !== ''));
    }

    /**
     * 一覧の見出しに出す文言。メールの件名にも同じものを使う(decisions #80)。
     * お知らせは管理者が付けたタイトルがそのまま見出しになる。
     */
    private function title(): string
    {
        return $this->announcement->title;
    }
}
