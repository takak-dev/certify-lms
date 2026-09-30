<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ChatMember;
use App\Models\ChatRoom;
use App\Models\User;

/**
 * ChatRoom リソースに対する認可ポリシー。
 *
 * - viewAny: admin / coach / student いずれも一覧自体は閲覧可(取得スコープは Action 側で絞る)
 * - view: admin は全件、coach / student は ChatMember として参加しているルームのみ
 * - postTo: admin は送信不可、coach / student は view 条件 + ルームの受講登録が受講解除(論理削除)されていないこと
 *   (解除後は過去のやり取りを読めるが、書き足せない。書き足せない点は受講生メモ #137 / #158 と同じだが、
 *    メモは解除後に読めない点が異なる。decisions #285)
 * - sendMessage: postTo 条件 + 担当コーチが資格に 1 人以上割当てられていること(画面の送信欄の出し分けに使う)
 *
 * 送信の POST(StoreMessageRequest)は postTo を見る。sendMessage を見ないのは、担当コーチ未割当を
 * 403 ではなく下の 422 に振り分けるため。受講解除の条件は postTo に置いたので、画面と URL の両方に効く(CLAUDE.md §3-7)。
 *
 * 担当コーチ未割当時は送信元(受講生 / コーチ)に応じて Controller 側で
 * `CertificationCoachNotAssignedForChatException` (422) を分岐 throw する想定。
 */
class ChatRoomPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Coach, UserRole::Student], true);
    }

    public function view(User $user, ChatRoom $room): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        return ChatMember::query()
            ->where('chat_room_id', $room->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function postTo(User $user, ChatRoom $room): bool
    {
        if ($user->role === UserRole::Admin) {
            return false;
        }

        if (! $this->view($user, $room)) {
            return false;
        }

        // ChatRoom::enrollment() は受講解除後も引ける(withTrashed)ので、trashed() で解除済みを判定できる。
        return ! $room->enrollment->trashed();
    }

    public function sendMessage(User $user, ChatRoom $room): bool
    {
        if (! $this->postTo($user, $room)) {
            return false;
        }

        $room->loadMissing('enrollment.certification.coaches');

        return $room->enrollment->certification->coaches->isNotEmpty();
    }
}
