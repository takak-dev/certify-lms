<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ChatMessage;
use App\UseCases\Chat\StoreMessageAction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ChatRoom に新しいメッセージが投稿された際に発火するブロードキャストイベント。
 *
 * StoreMessageAction が DB::transaction の afterCommit() 内で broadcast(...) する想定で、
 * Pusher 経由で当該 ChatRoom 全 ChatMember のブラウザに即時 push する。
 *
 * Payload には添付情報を含まない(添付ファイル機能は仕様上撤回)。
 *
 * @see StoreMessageAction
 * @see routes/channels.php
 */
final class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // ⚠️ 接続は sync に固定し、キューに積まずその場で配信する(decisions #272)。
    // ShouldBroadcast は既定の接続(QUEUE_CONNECTION)のキューに積んでから配信するため
    // (Illuminate/Broadcasting/BroadcastManager.php:181-183 が $event->connection を読む)、
    // database にすると相手の画面への反映が worker 待ちになる。リアルタイム配信は T-A-05 のスコープ外なので、
    // これまで(QUEUE_CONNECTION=sync)と同じ振る舞いに保つ。手本: SyncChatMembersOnCoachAssignmentChanged の $connection
    public string $connection = 'sync';

    public function __construct(public readonly ChatMessage $message) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("chat-room.{$this->message->chat_room_id}");
    }

    public function broadcastAs(): string
    {
        return 'ChatMessageSent';
    }

    /**
     * @return array{id: string, chat_room_id: string, body: string, sender_user_id: string, sender_name: string, sender_role: string, created_at: string}
     */
    public function broadcastWith(): array
    {
        $this->message->loadMissing('sender');

        return [
            'id' => $this->message->id,
            'chat_room_id' => $this->message->chat_room_id,
            'body' => $this->message->body,
            'sender_user_id' => $this->message->sender_user_id,
            'sender_name' => $this->message->sender->name,
            'sender_role' => $this->message->sender->role->value,
            'created_at' => $this->message->created_at->toIso8601String(),
        ];
    }
}
