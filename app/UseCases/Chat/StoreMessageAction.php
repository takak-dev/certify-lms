<?php

declare(strict_types=1);

namespace App\UseCases\Chat;

use App\Events\ChatMessageSent;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * ChatRoom にメッセージを INSERT し、送信者の既読時刻を更新したうえで Broadcast を発火する Action。
 *
 * - INSERT 後、ChatMessage::booted() が `chat_rooms.last_message_at` を denormalize 更新する
 * - 送信者自身の `ChatMember.last_read_at = now()` を UPDATE(自分のメッセージは未読としてカウントしない)
 * - 通信失敗が DB 整合性に波及しないよう Pusher Broadcast は `DB::afterCommit()` で送る
 * - 同じ理由で、同室の他メンバーへの通知(S-B-04)も `DB::afterCommit()` で送る
 * - 担当コーチ未割当の判定は Controller 側で実施済(`CertificationCoachNotAssignedForChatException` 振り分け)
 */
final class StoreMessageAction
{
    /**
     * @param array{body: string} $validated
     */
    public function __invoke(User $sender, ChatRoom $room, array $validated): ChatMessage
    {
        return DB::transaction(function () use ($sender, $room, $validated) {
            $message = ChatMessage::create([
                'chat_room_id' => $room->id,
                'sender_user_id' => $sender->id,
                'body' => $validated['body'],
            ]);

            ChatMember::query()
                ->where('chat_room_id', $room->id)
                ->where('user_id', $sender->id)
                ->update(['last_read_at' => now()]);

            DB::afterCommit(function () use ($message, $room, $sender): void {
                broadcast(new ChatMessageSent($message->load('sender')))->toOthers();

                // 同じルームの他メンバーへ通知する(送信者自身は除く)。
                // Notification::send() は複数の宛先へまとめて送るための入口で、
                // 1 人ずつ notify() を呼ぶのと結果は同じ。
                // 受け取ってよい相手かは通知クラス側が判定する(DeliversToActiveUsersOnly / decisions #76)。
                $recipients = User::query()
                    ->whereIn('id', $room->members()->pluck('user_id'))
                    ->whereKeyNot($sender->id)
                    ->get();

                Notification::send($recipients, new ChatMessageReceivedNotification($message));
            });

            return $message;
        });
    }
}
