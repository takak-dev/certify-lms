<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\ChatUnreadCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatUnreadCountServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_count_in_room_excludes_own_and_pre_read(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();

        ChatMember::factory()->create([
            'chat_room_id' => $room->id,
            'user_id' => $student->id,
            'last_read_at' => null,
        ]);

        ChatMessage::factory()->create([
            'chat_room_id' => $room->id,
            'sender_user_id' => $coach->id,
        ]);
        ChatMessage::factory()->create([
            'chat_room_id' => $room->id,
            'sender_user_id' => $coach->id,
        ]);
        ChatMessage::factory()->create([
            'chat_room_id' => $room->id,
            'sender_user_id' => $student->id,
        ]);

        $count = app(ChatUnreadCountService::class)->messageCountInRoom($room, $student);

        $this->assertSame(2, $count);
    }

    public function test_room_count_for_user_counts_only_rooms_with_unread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $enrollmentA = Enrollment::factory()->for($student)->create();
        $roomA = ChatRoom::factory()->for($enrollmentA)->create();
        ChatMember::factory()->create([
            'chat_room_id' => $roomA->id,
            'user_id' => $student->id,
            'last_read_at' => null,
        ]);
        ChatMessage::factory()->create([
            'chat_room_id' => $roomA->id,
            'sender_user_id' => $coach->id,
        ]);

        $enrollmentB = Enrollment::factory()->for($student)->create();
        $roomB = ChatRoom::factory()->for($enrollmentB)->create();
        ChatMember::factory()->create([
            'chat_room_id' => $roomB->id,
            'user_id' => $student->id,
            'last_read_at' => now(),
        ]);

        $count = app(ChatUnreadCountService::class)->roomCountForUser($student);

        $this->assertSame(1, $count);
    }

    public function test_room_count_for_user_excludes_rooms_with_only_own_messages(): void
    {
        // Arrange: 受講生が参加する2つのルームを用意する。
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        // ルームA: 相手(コーチ)の発言あり → 未読ルームとして数えるべき
        $enrollmentA = Enrollment::factory()->for($student)->create();
        $roomA = ChatRoom::factory()->for($enrollmentA)->create();
        ChatMember::factory()->create([
            'chat_room_id' => $roomA->id,
            'user_id' => $student->id,
            'last_read_at' => null,       // 一度も開いていない = 全メッセージが未読対象
        ]);
        ChatMessage::factory()->create([
            'chat_room_id' => $roomA->id,
            'sender_user_id' => $coach->id,
        ]);

        // ルームB: 自分(受講生)の発言だけ → 相手からの新着が無いので数えてはいけない
        $enrollmentB = Enrollment::factory()->for($student)->create();
        $roomB = ChatRoom::factory()->for($enrollmentB)->create();
        ChatMember::factory()->create([
            'chat_room_id' => $roomB->id,
            'user_id' => $student->id,
            'last_read_at' => null,       // A と同条件にして、差が「送信者」だけになるようにする
        ]);
        ChatMessage::factory()->create([
            'chat_room_id' => $roomB->id,
            'sender_user_id' => $student->id,   // ← ここだけが A との違い
        ]);

        // Act
        $count = app(ChatUnreadCountService::class)->roomCountForUser($student);

        // Assert: 数えるのはルームAだけ。B を含めて 2 になったら自分の発言を数えている。
        $this->assertSame(1, $count);
    }

    public function test_message_counts_by_room_returns_keyed_array_with_own_and_pre_read_excluded(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $enrollmentA = Enrollment::factory()->for($student)->create();
        $roomA = ChatRoom::factory()->for($enrollmentA)->create();
        ChatMember::factory()->create([
            'chat_room_id' => $roomA->id,
            'user_id' => $student->id,
            'last_read_at' => null,
        ]);
        // roomA に未読 2 件 + 自分送信 1 件
        ChatMessage::factory()->create(['chat_room_id' => $roomA->id, 'sender_user_id' => $coach->id]);
        ChatMessage::factory()->create(['chat_room_id' => $roomA->id, 'sender_user_id' => $coach->id]);
        ChatMessage::factory()->create(['chat_room_id' => $roomA->id, 'sender_user_id' => $student->id]);

        $enrollmentB = Enrollment::factory()->for($student)->create();
        $roomB = ChatRoom::factory()->for($enrollmentB)->create();
        ChatMember::factory()->create([
            'chat_room_id' => $roomB->id,
            'user_id' => $student->id,
            'last_read_at' => now(),
        ]);
        // roomB は last_read_at = now() なので、それより前のメッセージは既読扱い
        ChatMessage::factory()->create([
            'chat_room_id' => $roomB->id,
            'sender_user_id' => $coach->id,
            'created_at' => now()->subMinutes(5),
        ]);

        $counts = app(ChatUnreadCountService::class)->messageCountsByRoomForUser(
            collect([$roomA, $roomB]),
            $student,
        );

        $this->assertSame(2, $counts[$roomA->id]);
        $this->assertSame(0, $counts[$roomB->id]);
    }

    public function test_message_counts_by_room_returns_zero_for_non_member_room(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $enrollment = Enrollment::factory()->for($student)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();
        // student は ChatMember として参加していない
        ChatMessage::factory()->create(['chat_room_id' => $room->id, 'sender_user_id' => $coach->id]);

        $counts = app(ChatUnreadCountService::class)->messageCountsByRoomForUser(
            collect([$room]),
            $student,
        );

        $this->assertSame(0, $counts[$room->id]);
    }

    public function test_message_counts_by_room_returns_empty_array_for_empty_input(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $counts = app(ChatUnreadCountService::class)->messageCountsByRoomForUser(
            collect([]),
            $student,
        );

        $this->assertSame([], $counts);
    }
}
