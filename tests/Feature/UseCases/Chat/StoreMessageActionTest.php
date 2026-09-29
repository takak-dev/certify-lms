<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Chat;

use App\Events\ChatMessageSent;
use App\Models\ChatMember;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use App\UseCases\Chat\StoreMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * StoreMessageAction の責務:
 *
 * - ChatMessage INSERT + 送信者の ChatMember.last_read_at = now() 更新
 * - DB::afterCommit() で ChatMessageSent broadcast を発火
 * - ChatMessageSent はキューを通さずその場で配信し、相手への通知だけをキューに積む(T-A-05 / decisions #272)
 * - シグネチャは `__invoke(User, ChatRoom, array)`(E-3 撤回後の単一形態)
 */
class StoreMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_insert_message_and_update_sender_last_read_at(): void
    {
        Event::fake([ChatMessageSent::class]);

        $sender = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($sender)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();

        $senderMember = ChatMember::factory()->create([
            'chat_room_id' => $room->id,
            'user_id' => $sender->id,
            'last_read_at' => null,
        ]);
        ChatMember::factory()->create([
            'chat_room_id' => $room->id,
            'user_id' => $coach->id,
            'last_read_at' => null,
        ]);

        $message = app(StoreMessageAction::class)($sender, $room, ['body' => 'こんにちは']);

        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
            'chat_room_id' => $room->id,
            'sender_user_id' => $sender->id,
            'body' => 'こんにちは',
        ]);
        $this->assertNotNull($senderMember->fresh()->last_read_at);

        Event::assertDispatched(ChatMessageSent::class);
    }

    /**
     * キューの接続を database にしても、リアルタイム配信(ChatMessageSent)はキューに積まれず、
     * 相手への通知(ChatMessageReceivedNotification)だけがキューに積まれること(T-A-05 / decisions #272)。
     *
     * ⚠️ ShouldBroadcast は既定の接続のキューに積んでから配信する。接続を database にしただけだと
     * 相手の画面への反映が worker 待ちになる。リアルタイム配信は T-A-05 のスコープ外なので、
     * ChatMessageSent の `$connection = 'sync'` でその場の配信に保っていることをここで見張る。
     */
    public function test_broadcast_is_not_queued_while_notification_is_queued(): void
    {
        // Arrange: ① 本番と同じ database 接続に切り替える(phpunit.xml の sync のままだと差が出ない)
        //          ② 配信係は null(何もしない)に固定する。phpunit.xml は BROADCAST_DRIVER を指定しておらず、
        //             手元の .env が pusher だと本物の Pusher に送ってしまうため
        config(['queue.default' => 'database', 'broadcasting.default' => 'null']);

        $sender = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($sender)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $sender->id]);
        // 相手(コーチ)がいないと通知が 1 件も積まれず、「通知は積まれる」側を確かめられない
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $coach->id]);

        // Act
        app(StoreMessageAction::class)($sender, $room, ['body' => 'こんにちは']);

        // Assert: キューに積まれたジョブの名前(displayName)を並べる。
        //         BroadcastEvent の displayName はイベントのクラス名になる(Illuminate/Broadcasting/BroadcastEvent.php:142-145)
        $queued = DB::table('jobs')->pluck('payload')
            ->map(fn (string $payload) => json_decode($payload, true)['displayName'])
            ->all();
        // ① リアルタイム配信はキューに積まれていない(＝その場で配信された)
        $this->assertNotContains(ChatMessageSent::class, $queued);
        // ② 相手への通知はキューに積まれている(コーチ 1 人 × database / mail の 2 件)
        $this->assertSame([ChatMessageReceivedNotification::class, ChatMessageReceivedNotification::class], $queued);
    }

    public function test_signature_is_user_chat_room_array(): void
    {
        $reflection = new \ReflectionMethod(StoreMessageAction::class, '__invoke');
        $params = $reflection->getParameters();

        $this->assertCount(3, $params);
        $this->assertSame(User::class, $params[0]->getType()?->getName());
        $this->assertSame(ChatRoom::class, $params[1]->getType()?->getName());
        $this->assertSame('array', $params[2]->getType()?->getName());
    }
}
