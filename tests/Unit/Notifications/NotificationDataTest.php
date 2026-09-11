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
use Illuminate\Notifications\Notification;
use Tests\TestCase;

/**
 * 通知データ（`notifications.data`）が画面との約束を守っていることを固定する単体テスト。
 *
 * ⭐ このテストがある理由。
 * 画面はキーが無くても壊れない作りになっている——`$data['title'] ?? '通知'` のように
 * 既定値で受け止めるため、キー名を間違えても例外が出ず「なんとなく違う表示」になるだけ。
 * `notification_type` も同じで、値がずれるとアイコンが既定のベルに戻るだけで気づけない
 * （notification-row.blade.php:14-21 の match）。
 * S-B-01 では Enum のケース名を画面だけ見て決めて作り直しになった。同じ型の事故をここで止める。
 *
 * だから値は Enum を参照せず**文字列リテラルで**書く。Enum 側を書き換えたら、このテストが落ちる。
 */
class NotificationDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 画面が読むキー。notification-row.blade.php:9-12 と notifications/show.blade.php:13-15 が参照している。
     *
     * ⚠️ 運営お知らせ（admin_announcement）は `url` を持たない唯一の通知なので、この一括検査には混ぜない。
     * 遷移先の業務画面が無いことがフォールバックの条件そのものになっている（decisions #83）。
     * 専用の検査を下に置いてある。
     */
    private const REQUIRED_KEYS = ['notification_type', 'title', 'message', 'url'];

    /**
     * 4 種類の通知を実データから組み立てて返す。
     *
     * @return array<string, Notification> 期待する notification_type => 通知
     */
    private function makeAll(): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->forCertification($certification)->create(['user_id' => $student->id]);
        $reply = QaReply::factory()->forThread($thread)->forUser($coach)->create();

        $message = ChatMessage::factory()->create(['sender_user_id' => $student->id]);

        $reserved = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create();
        $canceled = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'canceled_by_user_id' => $student->id,
            'canceled_at' => now(),
        ]);

        return [
            'qa_reply_received' => new QaReplyReceivedNotification($reply),
            'chat_message_received' => new ChatMessageReceivedNotification($message),
            'meeting_reserved' => new MeetingReservedNotification($reserved),
            'meeting_canceled' => new MeetingCanceledNotification($canceled),
        ];
    }

    public function test_every_notification_has_the_keys_the_screen_reads(): void
    {
        // Arrange
        $recipient = User::factory()->student()->inProgress()->create();

        foreach ($this->makeAll() as $expectedType => $notification) {
            // Act
            $data = $notification->toDatabase($recipient);

            // Assert
            foreach (self::REQUIRED_KEYS as $key) {
                $this->assertArrayHasKey($key, $data, "{$expectedType} に {$key} が無い");
                $this->assertNotSame('', $data[$key], "{$expectedType} の {$key} が空");
            }
        }
    }

    public function test_notification_type_matches_the_values_the_screen_branches_on(): void
    {
        // Arrange
        $recipient = User::factory()->student()->inProgress()->create();

        foreach ($this->makeAll() as $expectedType => $notification) {
            // Act
            $data = $notification->toDatabase($recipient);

            // Assert: 値がずれるとアイコンが既定のベルになる（画面は例外を出さない）
            $this->assertSame($expectedType, $data['notification_type']);
        }
    }

    public function test_url_is_always_an_internal_relative_path(): void
    {
        // Arrange
        $recipient = User::factory()->student()->inProgress()->create();

        foreach ($this->makeAll() as $expectedType => $notification) {
            // Act
            $url = $notification->toDatabase($recipient)['url'];

            // Assert: 既読化はこの値へリダイレクトする。絶対 URL を入れると外部へ飛びうる
            $this->assertStringStartsWith('/', $url, "{$expectedType} の url が相対パスでない");
            $this->assertStringStartsNotWith('//', $url, "{$expectedType} の url が外部ホストとして解釈される");
        }
    }

    public function test_meeting_notifications_carry_meeting_id_for_the_reminder_check(): void
    {
        // Arrange: S-B-09 は専用ログテーブルを持たず、通知データを JSON 検索して
        //          リマインダーの重複配信を防ぐ（decisions #36）。その検索キーを固定する
        $recipient = User::factory()->student()->inProgress()->create();
        $all = $this->makeAll();

        foreach (['meeting_reserved', 'meeting_canceled'] as $type) {
            // Act
            $data = $all[$type]->toDatabase($recipient);

            // Assert
            $this->assertArrayHasKey('meeting_id', $data, "{$type} に meeting_id が無い");
        }

        // 面談以外は持たない（重複検査の対象外なので不要）
        $this->assertArrayNotHasKey('meeting_id', $all['qa_reply_received']->toDatabase($recipient));
        $this->assertArrayNotHasKey('meeting_id', $all['chat_message_received']->toDatabase($recipient));
    }

    /**
     * 運営お知らせ（S-B-08）のデータ。他の 4 種類と約束が違うので単独で検査する。
     *
     * ① `body` を持つ：通知詳細ページが全文をここから読む（notifications/show.blade.php:15）
     * ② `url` を持たない：遷移先の業務画面が無く、既読化のフォールバックで詳細ページへ送る
     *    （MarkAsReadAction。ここに値を入れるとフォールバックが働かなくなる）
     */
    public function test_admin_announcement_carries_the_full_body_and_no_url(): void
    {
        // Arrange
        $recipient = User::factory()->student()->inProgress()->create();
        $announcement = Announcement::factory()->create([
            'title' => '年末年始の運営休止について',
            'body' => "12 月 29 日から 1 月 3 日まで休止します。\n\nご不便をおかけします。",
        ]);

        // Act
        $data = (new AdminAnnouncementNotification($announcement))->toDatabase($recipient);

        // Assert: 値は Enum を参照せず文字列リテラルで書く（画面が分岐している値そのものを固定するため）
        $this->assertSame('admin_announcement', $data['notification_type']);
        $this->assertSame('年末年始の運営休止について', $data['title']);
        $this->assertSame($announcement->body, $data['body'], '詳細ページが読む body に本文全文が入っていない');
        $this->assertArrayNotHasKey('url', $data, 'url があるとフォールバックが働かず、通知詳細ページへ行けない');
    }

    /** 一覧のプレビューは長い本文でも 1 行に収まる長さに切る */
    public function test_admin_announcement_message_is_an_excerpt(): void
    {
        // Arrange
        $recipient = User::factory()->student()->inProgress()->create();
        $announcement = Announcement::factory()->create(['body' => str_repeat('あ', 300)]);

        // Act
        $data = (new AdminAnnouncementNotification($announcement))->toDatabase($recipient);

        // Assert
        $this->assertLessThan(
            mb_strlen($announcement->body),
            mb_strlen($data['message']),
            '一覧のプレビューが本文全文のままになっている',
        );
    }
}
