<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\ChatMember;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\User;
use App\Policies\ChatRoomPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatRoomPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_any_room(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $room = ChatRoom::factory()->for(Enrollment::factory())->create();

        $this->assertTrue((new ChatRoomPolicy)->view($admin, $room));
    }

    public function test_member_can_view_but_non_member_cannot(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $room = ChatRoom::factory()->for(Enrollment::factory())->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $student->id]);

        $policy = new ChatRoomPolicy;
        $this->assertTrue($policy->view($student, $room));
        $this->assertFalse($policy->view($other, $room));
    }

    public function test_admin_cannot_send_message(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $room = ChatRoom::factory()->for(Enrollment::factory())->create();

        $this->assertFalse((new ChatRoomPolicy)->sendMessage($admin, $room));
    }

    public function test_send_message_requires_at_least_one_coach(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $enrollment = Enrollment::factory()->for($student)->for($certification)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $student->id]);

        $policy = new ChatRoomPolicy;
        $this->assertFalse($policy->sendMessage($student, $room->fresh()), 'コーチ未割当時は送信不可');

        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($policy->sendMessage($student, $room->fresh()), 'コーチ割当後は送信可');
    }

    public function test_send_message_for_enrollment_method_does_not_exist(): void
    {
        $this->assertFalse(
            method_exists(ChatRoomPolicy::class, 'sendMessageForEnrollment'),
            'E-3 撤回: ChatRoom eager 生成で Enrollment ベース認可は不要',
        );
    }

    /**
     * 受講解除(受講登録の論理削除)したルームには、参加者でも書き足せないこと(decisions #285)。
     * postTo は送信の POST(StoreMessageRequest)が、sendMessage は画面の送信欄が見る。両方が同じ条件で拒否に変わる。
     */
    public function test_post_to_and_send_message_are_denied_after_unenrolling(): void
    {
        // Arrange: 担当コーチのいる資格の受講登録に、受講生が参加するルーム(解除前は送れる状態)。
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enrollment = Enrollment::factory()->for($student)->for($certification)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $student->id]);
        $policy = new ChatRoomPolicy;
        $this->assertTrue($policy->postTo($student, $room->fresh()), '解除前は送れる');

        // Act: 受講登録を論理削除する(受講解除と同じ状態)。
        $enrollment->delete();

        // Assert: 閲覧はできるが、postTo も sendMessage も拒否になる。
        $this->assertTrue($policy->view($student, $room->fresh()), '解除後も過去のやり取りは読める');
        $this->assertFalse($policy->postTo($student, $room->fresh()), '解除後は送信の POST が拒否される');
        $this->assertFalse($policy->sendMessage($student, $room->fresh()), '解除後は画面の送信欄も出ない');
    }
}
