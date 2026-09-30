<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Chat;

use App\Models\Certification;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\User;
use App\UseCases\Enrollment\DestroyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 受講生が受講解除したあとの、チャットの扱いを確かめる(decisions #285)。
 *
 * 受講解除(Enrollment\DestroyAction)は受講登録を論理削除するだけで、チャットルームは残る。
 * - 画面: ChatRoom::enrollment() が解除済みの受講登録を引けないと、ルームの見出し(chat-room/show.blade.php)と
 *   左の一覧(chat-room/_partials/rooms-pane.blade.php)が資格名を読めず 500 になっていた。
 *   左の一覧は最新 50 件なので、解除済みのルームが入っていると、ほかの受講生のルームも開けなかった
 * - 送信: 解除後は読み取り専用にする(ChatRoomPolicy::postTo)。過去のやり取りは読めるが書き足せない
 */
class UnenrolledEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_coach_can_read_room_after_student_unenrolls(): void
    {
        // Arrange: 受講生が受講解除したルーム。
        ['coach' => $coach, 'room' => $room, 'certificationName' => $certificationName] = $this->roomOfUnenrolledEnrollment();

        // Act: 担当コーチがそのルームを開く。
        $response = $this->actingAs($coach)->get(route('chat.show', $room));

        // Assert: 500 にならず開け、見出しに資格名が出て、解除前のメッセージも読める。送信欄の代わりに案内が出る。
        //   資格名は受講解除の前に控えた値と比べる(直したリレーションから取ると、そのリレーション自体を検証できない)。
        $response->assertOk();
        $response->assertSee($certificationName);
        $response->assertSee('受講解除前に送ったメッセージ');
        $response->assertSee('このルームでメッセージを送信する権限がありません。');
    }

    public function test_student_chat_index_opens_after_unenrolling(): void
    {
        // Arrange: 同上。受講生のチャット一覧は、参加ルームのうち最新の 1 件へ転送する。
        ['student' => $student] = $this->roomOfUnenrolledEnrollment();

        // Act: 受講解除した本人がチャット一覧を開き、転送先までたどる。
        $response = $this->actingAs($student)->followingRedirects()->get(route('chat.index'));

        // Assert: 転送先も含めて、500 にならず開ける。
        $response->assertOk();
    }

    public function test_admin_chat_audit_opens_after_student_unenrolls(): void
    {
        // Arrange: 同上。管理者は参加していなくても、監査画面で全ルームを見る。
        ['certificationName' => $certificationName] = $this->roomOfUnenrolledEnrollment();
        $admin = User::factory()->admin()->create();

        // Act: 管理者が監査画面を開き、転送先までたどる。
        $response = $this->actingAs($admin)->followingRedirects()->get(route('admin.chat-rooms.index'));

        // Assert: 500 にならず開け、解除したルームも資格名付きで並ぶ。
        $response->assertOk();
        $response->assertSee($certificationName);
    }

    public function test_student_cannot_post_to_room_after_unenrolling(): void
    {
        // Arrange: 同上。資格には担当コーチがいるので、受講解除さえしていなければ送れる状態。
        ['student' => $student, 'room' => $room] = $this->roomOfUnenrolledEnrollment();

        // Act: 受講解除した本人が、URL を直接叩いて送信する(画面には送信欄が出ない)。
        $response = $this->actingAs($student)->post(route('chat.storeMessage', $room), ['body' => '解除後に送ろうとした']);

        // Assert: 403 で拒否され、メッセージは増えない(画面の出し分けと同じ Policy で URL も塞ぐ。CLAUDE.md §3-7)。
        $response->assertForbidden();
        $this->assertDatabaseMissing('chat_messages', ['body' => '解除後に送ろうとした']);
    }

    public function test_coach_cannot_post_to_room_after_student_unenrolls(): void
    {
        // Arrange: 同上。
        ['coach' => $coach, 'room' => $room] = $this->roomOfUnenrolledEnrollment();

        // Act: 担当コーチが URL を直接叩いて送信する。
        $response = $this->actingAs($coach)->post(route('chat.storeMessage', $room), ['body' => '解除後にコーチが送ろうとした']);

        // Assert: コーチも同じく 403。受講解除したルームは、誰からも書き足せない。
        $response->assertForbidden();
        $this->assertDatabaseMissing('chat_messages', ['body' => '解除後にコーチが送ろうとした']);
    }

    /**
     * 担当コーチのいる資格の受講登録に、受講生とコーチが参加するルームを作り、受講生がメッセージを 1 通送ってから、
     * 受講生本人が受講解除する。ルームと参加者の作り方は Chat/ShowTest、担当の割り当ては
     * FetchCoachDashboardActionTest::attachCoach() と同じ。
     *
     * @return array{coach: User, student: User, room: ChatRoom, certificationName: string}
     */
    private function roomOfUnenrolledEnrollment(): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create(['name' => '解除テスト用の資格']);
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $room = ChatRoom::factory()->for($enrollment)->create(['last_message_at' => now()]);

        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $student->id]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $coach->id]);
        ChatMessage::factory()->create([
            'chat_room_id' => $room->id,
            'sender_user_id' => $student->id,
            'body' => '受講解除前に送ったメッセージ',
        ]);

        // 受講解除は本物の処理で行う(factory で deleted_at を入れるだけだと、実際の処理と食い違いうる)。
        app(DestroyAction::class)($enrollment);

        return ['coach' => $coach, 'student' => $student, 'room' => $room, 'certificationName' => '解除テスト用の資格'];
    }
}
