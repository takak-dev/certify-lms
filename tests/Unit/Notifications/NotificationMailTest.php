<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\Announcement;
use App\Models\Certification;
use App\Models\ChatMessage;
use App\Models\Meeting;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use App\Notifications\ChatMessageReceivedNotification;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 通知のメール経路を検証する Unit テスト。手本: Auth/ResetPasswordNotificationTest。
 *
 * ⭐ このテストがある理由。
 * 要件は「アプリ内通知**とメール**で知らせる」だが、Feature テストで使う `Notification::fake()` は
 * チャネルを実行しない。つまり `toMail()` が壊れていても Feature テストは全部緑のまま通る。
 * メールは要件の柱の片方なので、ここで直接叩いて固定する。
 */
class NotificationMailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 5 種類の通知を実データから組み立てて返す。
     *
     * @return array<string, Notification> 期待する件名 => 通知
     */
    private function makeAll(): array
    {
        $student = User::factory()->student()->inProgress()->create(['name' => '受講生テスト']);
        $coach = User::factory()->coach()->inProgress()->create(['name' => 'コーチテスト']);

        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->forCertification($certification)->create(['user_id' => $student->id]);
        $reply = QaReply::factory()->forThread($thread)->forUser($coach)->create();
        $message = ChatMessage::factory()->create(['sender_user_id' => $student->id]);
        $reserved = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create();
        $canceled = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'canceled_by_user_id' => $student->id,
            'canceled_at' => now(),
        ]);
        // 運営お知らせ（S-B-08）。件名は管理者が付けたタイトルがそのまま入る
        $announcement = Announcement::factory()->create(['title' => 'システムメンテナンス実施のお知らせ']);

        $all = [
            '[Certify LMS] あなたの質問に回答が届きました' => new QaReplyReceivedNotification($reply),
            '[Certify LMS] 受講生テスト さんからメッセージが届きました' => new ChatMessageReceivedNotification($message),
            '[Certify LMS] 面談が予約されました' => new MeetingReservedNotification($reserved),
            '[Certify LMS] 面談がキャンセルされました' => new MeetingCanceledNotification($canceled),
            '[Certify LMS] システムメンテナンス実施のお知らせ' => new AdminAnnouncementNotification($announcement),
        ];

        // 実際の送信では Laravel が冒頭で通知 id を採番してから各チャネルを呼ぶ
        // (vendor/laravel/framework/src/Illuminate/Notifications/NotificationSender.php:140-141)。
        // toMail() を直接叩くこのテストでは採番が走らないため、同じ状態を自分で作る。
        // 運営お知らせの action URL は通知詳細ページ(notifications.show)で、この id を使う
        foreach ($all as $notification) {
            $notification->id = (string) Str::uuid();
        }

        return $all;
    }

    public function test_every_notification_is_delivered_by_mail_as_well(): void
    {
        // Arrange
        $recipient = User::factory()->student()->inProgress()->create();

        foreach ($this->makeAll() as $subject => $notification) {
            // Act
            $channels = $notification->via($recipient);

            // Assert: 要件「アプリ内通知とメールで知らせる」。片方だけになっていないか
            $this->assertContains('database', $channels, "{$subject} が database チャネルを持たない");
            $this->assertContains('mail', $channels, "{$subject} が mail チャネルを持たない");
        }
    }

    public function test_subjects_follow_the_agreed_format(): void
    {
        // Arrange: 件名は `[Certify LMS] {通知タイトル}` で統一する（decisions #80）
        $recipient = User::factory()->student()->inProgress()->create();

        foreach ($this->makeAll() as $expectedSubject => $notification) {
            // Act
            $mail = $notification->toMail($recipient);

            // Assert
            $this->assertInstanceOf(MailMessage::class, $mail);
            $this->assertSame($expectedSubject, $mail->subject);
        }
    }

    public function test_subject_is_always_the_prefix_plus_the_notification_title(): void
    {
        // Arrange: 期待値をリテラルで持つだけだと「実装がそうなっている」ことしか確かめられない。
        //          decisions #80 のルール（接頭辞 + 通知タイトル）そのものをここで検査する。
        //          実際にチャット通知だけ件名とタイトルが食い違い、リテラル比較では素通りしていた
        $recipient = User::factory()->student()->inProgress()->create();

        foreach ($this->makeAll() as $subject => $notification) {
            // Act
            $mail = $notification->toMail($recipient);
            $title = $notification->toDatabase($recipient)['title'];

            // Assert
            $this->assertSame('[Certify LMS] '.$title, $mail->subject, "{$subject} の件名がタイトルと揃っていない");
        }
    }

    public function test_action_link_is_absolute_so_it_opens_from_a_mail_client(): void
    {
        // Arrange: 通知データの url は相対パスだが、メールのリンクは絶対 URL でないと開けない
        $recipient = User::factory()->student()->inProgress()->create();

        foreach ($this->makeAll() as $subject => $notification) {
            // Act
            $mail = $notification->toMail($recipient);

            // Assert
            $this->assertNotEmpty($mail->actionUrl, "{$subject} に action ボタンが無い");
            $this->assertStringStartsWith(config('app.url'), $mail->actionUrl, "{$subject} のリンクが絶対 URL でない");
        }
    }

    /**
     * 運営お知らせの action ボタンは通知詳細ページを指す（decisions #95）。
     *
     * ⭐ このテストがある理由。当初「toMail() の時点では通知 ID が確定していない」と誤って判断し、
     * 一覧へ送る実装にしていた。実際は Laravel が各チャネルの前に id を採番している
     * (NotificationSender.php:140-141)。同じ誤りに戻っても、上の「絶対 URL か」の検査は
     * 一覧でも通ってしまうため、行き先そのものをここで固定する。
     */
    public function test_admin_announcement_links_to_the_notification_detail_page(): void
    {
        // Arrange
        $recipient = User::factory()->student()->inProgress()->create();
        $announcement = Announcement::factory()->create();
        $notification = new AdminAnnouncementNotification($announcement);
        $notification->id = (string) Str::uuid();

        // Act
        $mail = $notification->toMail($recipient);

        // Assert: 一覧ではなく詳細ページ。URL には自分の通知 ID が入る
        $this->assertSame(route('notifications.show', $notification->id), $mail->actionUrl);
    }

    public function test_greeting_addresses_the_recipient_by_name(): void
    {
        // Arrange: 宛名は受け取った側の名前。差出人や操作者の名前と取り違えていないか
        $recipient = User::factory()->student()->inProgress()->create(['name' => '宛先の人']);

        foreach ($this->makeAll() as $subject => $notification) {
            // Act
            $mail = $notification->toMail($recipient);

            // Assert
            $this->assertStringContainsString('宛先の人', $mail->greeting, "{$subject} の宛名が受け取る人になっていない");
        }
    }
}
