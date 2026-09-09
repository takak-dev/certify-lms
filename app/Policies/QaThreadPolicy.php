<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Models\QaThread;
use App\Models\User;

/**
 * 質問掲示板スレッドの認可ルール。
 *
 * - student: 公開中の資格のスレッドを閲覧・投稿可。自分のスレッドのみ編集 / 削除 / 解決マーク可
 * - coach: 担当資格(certification_coach_assignments)かつ公開中のスレッドのみ閲覧可。投稿・編集はできない
 * - admin: 公開停止中を含む全資格のスレッドを閲覧・削除可。内容の編集と解決マークの代行はできない
 *
 * 「回答が付いているスレッドは削除できない」は認可ではなく業務ルールのため、ここでは判定しない
 * (DestroyAction が 409 を投げる。手本: `CertificationCategory\DestroyAction.php:22`)。
 */
class QaThreadPolicy
{
    /**
     * 一覧の閲覧。受講生とコーチの公開画面、管理者のモデレーション画面の双方で使う。
     */
    public function viewAny(User $auth): bool
    {
        return in_array($auth->role, [UserRole::Student, UserRole::Coach, UserRole::Admin], true);
    }

    /**
     * 詳細の閲覧。コーチの担当外は 403(decisions #40)、公開停止資格は管理者のみ。
     */
    public function view(User $auth, QaThread $thread): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            UserRole::Student => $this->certificationIsPublished($thread),
            UserRole::Coach => $this->certificationIsPublished($thread) && $this->assignedCoach($auth, $thread),
            default => false,
        };
    }

    /**
     * スレッドの投稿。受講生のみ(受講していない資格にも質問できる)。
     */
    public function create(User $auth): bool
    {
        return $auth->role === UserRole::Student;
    }

    /**
     * 内容の編集。投稿者本人のみ(管理者にも許可しない)。
     */
    public function update(User $auth, QaThread $thread): bool
    {
        return $this->isAuthor($auth, $thread) && $this->certificationIsPublished($thread);
    }

    /**
     * 削除。投稿者本人に加え、モデレーションとして管理者にも許可する(decisions #37)。
     */
    public function delete(User $auth, QaThread $thread): bool
    {
        if ($auth->role === UserRole::Admin) {
            return true;
        }

        return $this->isAuthor($auth, $thread) && $this->certificationIsPublished($thread);
    }

    /**
     * 解決済へのマーク。投稿者本人のみ(管理者の代行はスコープ外)。
     */
    public function resolve(User $auth, QaThread $thread): bool
    {
        return $this->update($auth, $thread);
    }

    /**
     * 未解決へ戻す。resolve と同じ条件。
     */
    public function unresolve(User $auth, QaThread $thread): bool
    {
        return $this->update($auth, $thread);
    }

    private function isAuthor(User $auth, QaThread $thread): bool
    {
        return $thread->user_id === $auth->id;
    }

    private function certificationIsPublished(QaThread $thread): bool
    {
        return $thread->certification?->status === CertificationStatus::Published;
    }

    private function assignedCoach(User $coach, QaThread $thread): bool
    {
        return $thread->certification?->coaches()->where('users.id', $coach->id)->exists() ?? false;
    }
}
