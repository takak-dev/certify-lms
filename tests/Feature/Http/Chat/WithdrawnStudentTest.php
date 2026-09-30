<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Chat;

use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\UserWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講生が退会したあとも、その受講生のチャットルームを担当コーチ・管理者が開けることを確かめる(decisions #284)。
 *
 * 退会(UserWithdrawalService)は users を論理削除するだけで、受講登録とチャットルームは残る。
 * Enrollment::user() が論理削除済みのユーザーを引かないと、ルームの見出し
 * (chat-room/show.blade.php の `$room->enrollment->user->name`)と左の一覧
 * (chat-room/_partials/rooms-pane.blade.php の `$r->enrollment->user->name`)が 500 になる。
 * 左の一覧は受講生以外(コーチ・管理者)に出る最新 50 件(ChatRoomController の limit(50))で、
 * そこに退会者のルームが入っていると、どのルームを開いても 500 になっていた(管理者の監査画面も同じ一覧を共有する)。
 */
class WithdrawnStudentTest extends TestCase
{
    use RefreshDatabase;

    public function test_coach_can_open_room_of_withdrawn_student(): void
    {
        // Arrange: 退会前の受講生がメッセージを 1 通送っているルーム。
        [$coach, $student, $room] = $this->roomOfWithdrawnStudent();

        // Act: 担当コーチがそのルームを開く。
        $response = $this->actingAs($coach)->get(route('chat.show', $room));

        // Assert: 500 にならず開け、見出しに退会者の氏名が出て、メッセージの本文も残っている。
        //   ⚠️ 吹き出しの送信者名は「送信者」と表示される。送信者は ChatMessage::sender() で引いており、
        //   こちらには withTrashed が無いため(message-item.blade.php の `?? '送信者'`。500 にはならない)。
        //   氏名を出すかは別の判断として残してあるので、ここでは今の表示をそのまま固定する。
        $response->assertOk();
        $response->assertSee($student->name);
        $response->assertSee('退会前に送ったメッセージ');
        $response->assertSee('送信者');
    }

    public function test_coach_chat_index_opens_when_it_redirects_to_withdrawn_students_room(): void
    {
        // Arrange: 同上。コーチ用のチャット一覧は、参加ルームのうち最新の 1 件へ転送する
        //   (ChatRoomController::indexAsCoach())。ルームが 1 つだけなので、転送先はこのルームになる。
        [$coach] = $this->roomOfWithdrawnStudent();

        // Act: 担当コーチがチャット一覧を開き、転送先までたどる。
        $response = $this->actingAs($coach)->followingRedirects()->get(route('coach.chat.index'));

        // Assert: 転送先も含めて、500 にならず開ける。
        $response->assertOk();
    }

    public function test_admin_chat_audit_opens_when_a_withdrawn_students_room_exists(): void
    {
        // Arrange: 退会者のルームが 1 つある。管理者は参加していなくても、監査画面で全ルームを見る。
        [, $student] = $this->roomOfWithdrawnStudent();
        $admin = User::factory()->admin()->create();

        // Act: 管理者が監査画面を開く(最新のルームへ転送されるので、転送先までたどる)。
        $response = $this->actingAs($admin)->followingRedirects()->get(route('admin.chat-rooms.index'));

        // Assert: 500 にならず開け、左の一覧に退会者の氏名が出る。
        $response->assertOk();
        $response->assertSee($student->name);
    }

    /**
     * 受講登録のチャットルームに受講生と担当コーチを参加させ、受講生がメッセージを 1 通送ってから退会させる。
     * ルームと参加者の作り方は Chat/ShowTest と同じ。
     *
     * @return array{0: User, 1: User, 2: ChatRoom}
     */
    private function roomOfWithdrawnStudent(): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $room = ChatRoom::factory()->for($enrollment)->create(['last_message_at' => now()]);

        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $student->id]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $coach->id]);
        ChatMessage::factory()->create([
            'chat_room_id' => $room->id,
            'sender_user_id' => $student->id,
            'body' => '退会前に送ったメッセージ',
        ]);

        // 退会は本物の処理で行う(factory で status だけ変えると、論理削除が起きず再現しない)。
        app(UserWithdrawalService::class)->withdraw($student);

        return [$coach, $student, $room];
    }
}
