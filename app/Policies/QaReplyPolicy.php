<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;

/**
 * 質問掲示板の回答の認可ルール。
 *
 * - student / coach: 閲覧できるスレッドに回答を投稿できる。自分の回答のみ編集 / 削除可
 * - admin: 回答を投稿・編集できない(モデレーションとしての削除のみ可)
 *
 * 投稿可否はスレッド側の閲覧可否に従属するため、`QaThreadPolicy::view()` へ委譲する
 * (公開停止資格・コーチの担当外の判定を二重に書かないため)。
 */
class QaReplyPolicy
{
    public function __construct(
        private readonly QaThreadPolicy $threadPolicy,
    ) {}

    /**
     * 回答の投稿。Blade は `can('create', [QaReply::class, $thread])` と第2引数にスレッドを渡す
     * (`_reply-form.blade.php:3`)。管理者は投稿できない(原典「管理者は回答できない」)。
     */
    public function create(User $auth, QaThread $thread): bool
    {
        if (! in_array($auth->role, [UserRole::Student, UserRole::Coach], true)) {
            return false;
        }

        return $this->threadPolicy->view($auth, $thread);
    }

    /**
     * 回答の編集。投稿者本人のみ(管理者にも許可しない)。
     */
    public function update(User $auth, QaReply $reply): bool
    {
        if (! $this->isAuthor($auth, $reply)) {
            return false;
        }

        return $reply->qaThread !== null && $this->threadPolicy->view($auth, $reply->qaThread);
    }

    /**
     * 回答の削除。投稿者本人に加え、モデレーションとして管理者にも許可する。
     * 回答側には削除条件を設けない(decisions #39)。
     */
    public function delete(User $auth, QaReply $reply): bool
    {
        if ($auth->role === UserRole::Admin) {
            return true;
        }

        return $this->update($auth, $reply);
    }

    private function isAuthor(User $auth, QaReply $reply): bool
    {
        return $reply->user_id === $auth->id;
    }
}
