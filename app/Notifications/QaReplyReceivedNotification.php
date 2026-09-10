<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\QaReply;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * 自分が立てた質問スレッドに回答が付いたことを知らせる通知。
 *
 * 宛先はスレッドの投稿者。誰に送るか(と、そもそも送ってよいか)は発火側が決めるため、
 * このクラスは「何を届けるか」だけを持つ。
 *
 * ⚠️ ShouldQueue は付けない。メール配信のキュー化は T-A-05 の担当で、
 * ここで付けると T-A-05 の失敗テストが意図せず通り、チケットの境界が壊れる。
 */
class QaReplyReceivedNotification extends Notification
{
    // 配信対象の制御(decisions #76)。受講中でない人・管理者には送らない
    use DeliversToActiveUsersOnly;

    /** メール件名の接頭辞。件名は「接頭辞 + 通知タイトル」で統一する（decisions #80） */
    private const SUBJECT_PREFIX = '[Certify LMS] ';

    public function __construct(private readonly QaReply $reply) {}

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
     * キー名は画面が決めている(notification-row.blade.php:9-12 / notifications/show.blade.php:13-15)。
     * `url` は既読化したあとの遷移先(decisions #42)。外部サイトへ飛ばさないよう相対パスで持つ。
     *
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'notification_type' => NotificationType::QaReplyReceived->value,
            'title' => $this->title(),
            'message' => $this->summary(),
            // 第 3 引数 false で相対パスになる(/qa-board/xxxx)
            'url' => route('qa-board.show', $this->reply->qa_thread_id, false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(self::SUBJECT_PREFIX.$this->title())
            ->greeting($notifiable->name.' 様')
            ->line('質問「'.$this->reply->qaThread->title.'」に回答が届きました。')
            ->line($this->summary())
            // メールのリンクは相対パスでは開けないため絶対 URL を渡す
            ->action('回答を見る', route('qa-board.show', $this->reply->qa_thread_id))
            ->salutation('Certify LMS 運営チーム');
    }

    /**
     * 一覧のプレビューとメール本文で使う抜粋。
     * 長い回答をそのまま入れると一覧が読みにくくなるため頭 100 文字で切る。
     */
    private function summary(): string
    {
        return Str::limit($this->reply->body, 100);
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
        return 'あなたの質問に回答が届きました';
    }
}
