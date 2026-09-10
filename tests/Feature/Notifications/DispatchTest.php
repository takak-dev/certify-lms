<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Certification;
use App\Models\ChatMember;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use App\Notifications\QaReplyReceivedNotification;
use App\UseCases\Chat\StoreMessageAction;
use App\UseCases\QaReply\StoreAction as QaReplyStoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * 業務イベントが起きたときに、当事者へ通知が飛ぶことの検証。
 *
 * 📌 置き場所について。Action 単位のテストは通常 `tests/Feature/UseCases/{Entity}/` に置くが
 * （ONBOARDING.md §2、手本: tests/Feature/UseCases/Chat/StoreMessageActionTest.php）、
 * 「誰に通知が飛ぶか」は Q&A・チャット・面談を横断する 1 つのルール（decisions #76 / #77 / #79）なので、
 * 種別ごとに散らさず 1 ファイルに集約した。横断ディレクトリの前例は tests/Feature/Broadcasting/ にある。
 *
 * 発火は `DB::afterCommit()` に置いてある（処理が巻き戻ったときに通知だけ残さないため）。
 * テストは RefreshDatabase でトランザクションに包まれるが、Laravel は
 * テスト用の外側トランザクションを無視して内側のコミットで afterCommit を走らせる。
 * ここが動かないと本番で通知が飛ばないため、まずそこを固定する。
 */
class DispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_qa_reply_notifies_the_thread_owner(): void
    {
        // Arrange
        Notification::fake();
        $owner = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->forCertification($certification)->create(['user_id' => $owner->id]);
        $answerer = User::factory()->coach()->create();

        // Act
        (new QaReplyStoreAction)($answerer, $thread, ['body' => '同じところでつまずきました。']);

        // Assert: 質問した本人にだけ届く
        Notification::assertSentTo($owner, QaReplyReceivedNotification::class);
        Notification::assertNotSentTo($answerer, QaReplyReceivedNotification::class);
    }

    public function test_qa_reply_by_the_owner_notifies_nobody(): void
    {
        // Arrange: 質問した本人が自分のスレッドに回答する
        Notification::fake();
        $owner = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->forCertification($certification)->create(['user_id' => $owner->id]);

        // Act
        (new QaReplyStoreAction)($owner, $thread, ['body' => '自己解決しました。']);

        // Assert: 自分の操作を自分に知らせない（decisions #79）
        Notification::assertNothingSent();
    }

    public function test_chat_message_notifies_the_other_member_only(): void
    {
        // Arrange: 2 人が入っているルーム
        Notification::fake();
        [$room, $sender, $receiver] = $this->makeChatRoomWithTwoMembers();

        // Act
        (new StoreMessageAction)($sender, $room, ['body' => 'よろしくお願いします。']);

        // Assert: 送信者自身には届かない
        Notification::assertSentTo($receiver, ChatMessageReceivedNotification::class);
        Notification::assertNotSentTo($sender, ChatMessageReceivedNotification::class);
    }

    public function test_graduated_user_receives_nothing(): void
    {
        // Arrange: 受け手が修了者（decisions #34 で配信対象外）
        Notification::fake();
        $graduated = User::factory()->student()->graduated()->create();
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->forCertification($certification)->create(['user_id' => $graduated->id]);
        $answerer = User::factory()->coach()->create();

        // Act
        (new QaReplyStoreAction)($answerer, $thread, ['body' => '参考になれば。']);

        // Assert: DeliversToActiveUsersOnly が shouldSend で止める
        Notification::assertNotSentTo($graduated, QaReplyReceivedNotification::class);
    }

    public function test_admin_receives_nothing(): void
    {
        // Arrange: 管理者は status が in_progress でも配信対象外（decisions #76）。
        //          状態だけを見る実装だと素通りしてしまう組み合わせ
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->forCertification($certification)->create(['user_id' => $admin->id]);
        $answerer = User::factory()->coach()->create();

        // Act
        (new QaReplyStoreAction)($answerer, $thread, ['body' => '確認しました。']);

        // Assert
        $this->assertSame('in_progress', $admin->status->value, '前提: 管理者の状態は受講中で登録される');
        Notification::assertNotSentTo($admin, QaReplyReceivedNotification::class);
    }

    /**
     * 受講生とコーチが 1 人ずつ入ったチャットルームを作る。
     *
     * @return array{0: ChatRoom, 1: User, 2: User} ルーム / 送信者(受講生) / 受信者(コーチ)
     */
    private function makeChatRoomWithTwoMembers(): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->create(['user_id' => $student->id]);
        $room = ChatRoom::factory()->create(['enrollment_id' => $enrollment->id]);

        // ULID の手採番をテスト側に散らさない（手本: tests/Feature/UseCases/Chat/StoreMessageActionTest.php）
        foreach ([$student, $coach] as $member) {
            ChatMember::factory()->create([
                'chat_room_id' => $room->id,
                'user_id' => $member->id,
            ]);
        }

        return [$room, $student, $coach];
    }
}
