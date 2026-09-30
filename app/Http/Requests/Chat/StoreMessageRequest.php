<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\ChatRoom;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ChatRoom にメッセージを送信する際の入力検証。
 *
 * authorize は `Policy::postTo`(= ChatMember として参加しているか + 受講解除されていないか)で判定し、
 * 「担当コーチ未割当」のような業務ガードは Controller 上で
 * `CertificationCoachNotAssignedForChatException` (422) に振り分ける。
 * これにより認可違反(403)と業務ガード(422)の応答コードが正しく分かれる。
 */
class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');

        // postTo = view + 受講解除されていないこと(ChatRoomPolicy。画面の送信欄の sendMessage と同じ条件を土台にする)。
        return $room instanceof ChatRoom
            && $this->user()?->can('postTo', $room) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }
}
