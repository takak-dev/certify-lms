<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Chat;

use App\Events\CertificationCoachAttached;
use App\Listeners\SyncChatMembersOnCoachAssignmentChanged;
use App\Models\Certification;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\ChatMemberSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 担当コーチ追加時に Listener が起動し、該当資格の全 ChatRoom に ChatMember が追加されることを検証する。
 */
class CoachAssignmentChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_syncs_chat_members_when_coach_attached(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $newCoach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $enrollment = Enrollment::factory()->for($student)->for($certification)->create();
        $room = ChatRoom::create([
            'enrollment_id' => $enrollment->id,
            'last_message_at' => null,
        ]);

        $this->assertDatabaseMissing('chat_members', [
            'chat_room_id' => $room->id,
            'user_id' => $newCoach->id,
        ]);

        $certification->coaches()->attach($newCoach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new SyncChatMembersOnCoachAssignmentChanged(app(ChatMemberSyncService::class)))
            ->handle(new CertificationCoachAttached($certification->fresh(), $newCoach, $admin));

        $this->assertDatabaseHas('chat_members', [
            'chat_room_id' => $room->id,
            'user_id' => $newCoach->id,
        ]);
    }

    /**
     * キューの接続を database にしても、チャットメンバー同期はキューに積まれず、その場で実行されること
     * (T-A-05 / decisions #266)。
     *
     * ⚠️ このリスナーは ShouldQueue を持ち、`$queue = 'database'`(接続名ではなくキューの名前)を指定している。
     * 接続を database にすると `database` という名前の列に積まれ、既定の `queue:work`(default しか見ない)が
     * 拾わず同期が永久に止まる。`$connection = 'sync'` で固定したことをここで見張る。
     *
     * ⭐ 上のテストと違い handle() を直接呼ばず、イベントを dispatch する。
     * 接続の選択はイベントの配信係(Illuminate/Events/Dispatcher.php:620-622)が行うため、
     * そこを通さないと $connection の指定が効いているかを確かめられない。
     */
    public function test_listener_runs_immediately_even_when_queue_connection_is_database(): void
    {
        // Arrange: テストの既定(phpunit.xml の QUEUE_CONNECTION=sync)を、本番と同じ database に切り替える。
        //          sync のままだと、固定の有無にかかわらずその場で実行されるので差が出ない
        config(['queue.default' => 'database']);

        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $newCoach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $enrollment = Enrollment::factory()->for($student)->for($certification)->create();
        $room = ChatRoom::create([
            'enrollment_id' => $enrollment->id,
            'last_message_at' => null,
        ]);

        $certification->coaches()->attach($newCoach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Act: 本番(AttachAction)と同じ入口でイベントを発火する
        CertificationCoachAttached::dispatch($certification->fresh(), $newCoach, $admin);

        // Assert: ① worker を動かしていないのに、もう同期が済んでいる(＝その場で実行された)
        $this->assertDatabaseHas('chat_members', [
            'chat_room_id' => $room->id,
            'user_id' => $newCoach->id,
        ]);
        // ② キューには何も積まれていない
        $this->assertSame(0, DB::table('jobs')->count());
    }
}
