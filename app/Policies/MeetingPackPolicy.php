<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MeetingPack;
use App\Models\User;

/**
 * 面談パック(追加購入用 SKU)マスタ管理の認可ルール。admin のみ。
 *
 * 原典「面談パック管理画面の全操作は管理者のみ。受講生 / コーチはアクセス拒否」に対応する。
 *
 * ルートは role:admin ミドルウェアの中に置くので判定は二重になるが、
 * 既存の管理者専用マスタと同じ形にしてある(手本: AnnouncementPolicy / CertificationCategoryPolicy)。
 * 入口(ミドルウェア)と個々の操作(Policy)で守る層を分ける。
 *
 * ⚠️ この Policy は「状態(draft / published / archived)」を一切見ない。
 * 「下書きからしか公開できない」といった遷移の正しさは Action 側で拒否する。
 * 画面(show.blade.php)も @can(権限) と @if(状態) を別々に書いており、その分担に揃えてある。
 */
class MeetingPackPolicy
{
    /** 一覧を見る */
    public function viewAny(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /**
     * 詳細を見る。第2引数は使わないが、
     * Laravel が「モデル1件に対する判定」としてインスタンスを渡してくるため受け取る。
     */
    public function view(User $auth, MeetingPack $meetingPack): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /** 新規作成(フォームの表示と実行の両方に使う) */
    public function create(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /** 基本情報の編集(状態はこのフォームでは変更しない) */
    public function update(User $auth, MeetingPack $meetingPack): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /** 削除。「公開中は削除できない」という状態の条件は Action 側で判定する */
    public function delete(User $auth, MeetingPack $meetingPack): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /** 下書き → 公開中 */
    public function publish(User $auth, MeetingPack $meetingPack): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /** 公開中 → アーカイブ */
    public function archive(User $auth, MeetingPack $meetingPack): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /** アーカイブ → 下書き(画面のラベルは「下書きへ戻す」) */
    public function unarchive(User $auth, MeetingPack $meetingPack): bool
    {
        return $auth->role === UserRole::Admin;
    }
}
