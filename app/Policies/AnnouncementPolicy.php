<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\User;

/**
 * お知らせ配信の認可ルール。admin のみ。
 *
 * 配信は不可逆(再配信 / 編集 / 取消なし)のため、update / delete は定義しない。
 * ルート自体を作らない方針(原典「お知らせには編集 / 削除 / 再配信のルートを設けない」)と
 * 揃えて、Policy にも操作を生やさない。
 *
 * ルートは role:admin ミドルウェアの中に置くので判定は二重になるが、
 * 既存の管理者専用マスタと同じ形にしてある(手本: CertificationCategoryPolicy)。
 * 入口(ミドルウェア)と個々の操作(Policy)で守る層を分ける。
 */
class AnnouncementPolicy
{
    public function viewAny(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /**
     * 配信履歴の詳細を見る。第2引数は使わないが、
     * Laravel が「モデル1件に対する判定」としてインスタンスを渡してくるため受け取る。
     */
    public function view(User $auth, Announcement $announcement): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /** 新規配信(作成フォームの表示と実行の両方に使う) */
    public function create(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }
}
