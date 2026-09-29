<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\MeetingReminderWindow;
use App\Enums\NotificationType;
use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 予約済み面談の事前リマインダー (S-B-09)。
 *
 * 宛先は当該面談の当事者 2 名(受講生と担当コーチ)。管理者は当事者ではないため対象外で、
 * 判定は shouldSend() が行う(decisions #34 / #76)。予約 / キャンセルの通知と違い、
 * 誰かの操作ではなく Schedule Command が時刻を見て送る。
 *
 * ⭐ この通知の data は「送信済み台帳」を兼ねる。
 * 重複配信の検査に専用テーブルを作らないと決めたため(decisions #36)、
 * 「この受信者に、この面談の、この window のリマインダーを送ったか」は
 * notifications テーブルの `meeting_id` と `reminder_window` を引いて判定する
 * (decisions #104)。この 2 つのキーを落とすと重複検査が成立しない。
 *
 * ⭐ キューで送る(T-A-05)。送信の本体は worker(`sail artisan queue:work`)が行い、
 * 宛先 × チャネル(database / mail)ごとに別々のジョブになる(Illuminate/Notifications/NotificationSender.php:188-235)。
 * 失敗したら待ってやり直す(RetriesWithBackoff / decisions #271)。
 */
class MeetingReminderNotification extends Notification implements ShouldQueue
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

    public function __construct(
        private readonly Meeting $meeting,
        private readonly MeetingReminderWindow $window,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * チャネルごとの接続先。アプリ内通知(database)だけはキューに積まず、その場で書き込む(decisions #267)。
     *
     * ⚠️ この通知の database の行は「送信済み台帳」を兼ねる(上の PHPDoc)。全チャネルをキューに積むと
     * 行を書くのが worker になり、worker が遅れている間に次の巡回(1 時間前は 10 分幅を 5 分間隔で見る)が
     * 「まだ送っていない」と判定して同じ宛先に再度積む → 二重送信になる。
     * 台帳をその場で書けば、次の巡回は必ず送信済みと判定できる。
     *
     * mail はここに書かないので既定の接続(QUEUE_CONNECTION)でキューに積まれ、失敗したら
     * RetriesWithBackoff の決まりでやり直す。
     * Laravel はこのメソッドをチャネルごとに引く(Illuminate/Notifications/NotificationSender.php:204-206)。
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'notification_type' => NotificationType::MeetingReminder->value,
            'title' => $this->title(),
            'message' => $this->scheduledAt().'／'.$this->counterpart($notifiable),
            'url' => route('meetings.show', $this->meeting->id, false),
            // ここから 2 つが重複検査の材料。画面は読まないが落としてはいけない(decisions #104)
            'meeting_id' => $this->meeting->id,
            'reminder_window' => $this->window->value,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(self::SUBJECT_PREFIX.$this->title())
            ->greeting($notifiable->name.' 様')
            ->line($this->lead())
            ->line('日時: '.$this->scheduledAt())
            ->line($this->counterpart($notifiable))
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
     * 宛先から見た「相手方」。受講生にはコーチ名を、コーチには受講生名を出す。
     *
     * ⚠️ 「受講生でなければコーチ」と割り切っている。呼び出し元(SendRemindersAction)が
     *    当事者 2 名にしか送らないことに依存する。第三者へ送る使い方が出てきたら、
     *    coach_id との一致も明示的に見る形へ直すこと(当事者でない人に氏名が出るため)。
     *
     * ラベルの言い回しは画面に合わせた
     * (meeting/index.blade.php:65「担当コーチ: 」/ meeting/coach/index.blade.php:56「受講生: 」)。
     *
     * 退会した当事者でも氏名をそのまま出す(decisions #46 / #67 / #105)。
     * coach() / student() が withTrashed で引くため、当事者が取れないことは起こらない
     * (coach_id / student_id は必須 + restrictOnDelete)。
     * MeetingCanceledNotification の canceledBy が「相手方」というフォールバックを持つのは、
     * あちらのカラム(canceled_by_user_id)が任意で null がありうるため。性質が違う。
     */
    private function counterpart(object $notifiable): string
    {
        if ($notifiable->id === $this->meeting->student_id) {
            return '担当コーチ: '.$this->meeting->coach->name;
        }

        return '受講生: '.$this->meeting->student->name;
    }

    /**
     * メール本文の書き出し。window で変わる唯一の本文。
     */
    private function lead(): string
    {
        return match ($this->window) {
            MeetingReminderWindow::Eve => '明日、面談の予定があります。',
            MeetingReminderWindow::OneHourBefore => 'まもなく面談の開始時刻です。',
        };
    }

    /**
     * 一覧の見出しに出す文言。メールの件名にも同じものを使う。
     *
     * 2 箇所で別々に書くと片方だけ直したときに食い違う（decisions #80 は
     * 「件名は `[Certify LMS] {通知タイトル}`」と定めている）。
     */
    private function title(): string
    {
        return match ($this->window) {
            MeetingReminderWindow::Eve => '明日 面談の予定があります',
            MeetingReminderWindow::OneHourBefore => 'まもなく面談が始まります',
        };
    }
}
