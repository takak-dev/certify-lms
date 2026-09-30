<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AiChatConversation;
use App\Models\User;

/**
 * AI 相談(S-A-02)の会話に対する認可ポリシー。
 *
 * ⚠️ 操作できるのは会話を作った受講生本人だけ。**コーチにも管理者にも一切許可しない**。
 *    原典スコープ外に「他受講生の会話履歴閲覧(管理者 / コーチ含む)— プライバシー / 監査外」と
 *    明記されているため。管理者の例外を持つ QaThreadPolicy::delete() を流用しないこと。
 *    権限の形が近いのは EnrollmentGoalPolicy(受講生本人だけ・管理者に特権なし)。
 *
 * 状態は一切見ない。層の分担は EnrollmentGoalPolicy の方針に揃える
 * (「Policy は権限、状態は Action」)。
 * - 修了済 / 退会済 / 招待中 … ルートの active-learning ミドルウェアが弾く
 * - 機能そのものの ON / OFF  … config('ai-chat.enabled') を見てルートごと登録しない
 *
 * 一覧(GET /ai-chat)の ability は置かない。表示対象は常に「自分の会話」だけで、
 * 判定に使うモデルが存在しないため。受講生以外を弾くのは role:student ミドルウェアの担当。
 */
class AiChatConversationPolicy
{
    /**
     * 会話を始められるか。まだ会話の行が無いのでモデルを取らない。
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    /**
     * 会話を開けるか(フル画面表示 / ウィジェットの履歴復元)。
     */
    public function view(User $user, AiChatConversation $conversation): bool
    {
        return $this->isOwner($user, $conversation);
    }

    /**
     * タイトルの変更。
     */
    public function update(User $user, AiChatConversation $conversation): bool
    {
        return $this->isOwner($user, $conversation);
    }

    /**
     * 会話の削除。⚠️ 管理者にも許可しない(上記のとおり原典スコープ外)。
     */
    public function delete(User $user, AiChatConversation $conversation): bool
    {
        return $this->isOwner($user, $conversation);
    }

    /**
     * その会話にメッセージを送れるか。
     *
     * ⚠️ AiChatMessage 用の Policy を別に作らず、親の ability として持つ。
     *    直接の手本は ChatRoomPolicy::sendMessage() ——
     *    あちらも子(ChatMessage)専用の Policy を作らず、親が書き込み権限を持っている
     *    (ChatMessagePolicy はリポジトリに存在しない)。
     *    子に専用クラスを立てている QaReplyPolicy は、回答自体に編集 / 削除の ability が
     *    あるため。AI 相談の発言は**編集も削除もできない**(原典のインターフェース表に
     *    その HTTP が無い)ので、こちらは ChatRoom と同じ形になる。
     */
    public function createMessage(User $user, AiChatConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    /**
     * 会話のオーナー本人か。QaThreadPolicy::isAuthor() と同じ形。
     */
    private function isOwner(User $user, AiChatConversation $conversation): bool
    {
        return $user->role === UserRole::Student
            && $conversation->user_id === $user->id;
    }
}
