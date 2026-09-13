<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\User;

/**
 * 受講プラン(受講期間 + 初期付与面談回数)マスタ管理の認可ルール。admin のみ。
 *
 * 原典「プラン管理画面の全操作は管理者のみ。受講生 / コーチはアクセス不可」に対応する。
 *
 * ルートは role:admin ミドルウェア(routes/web.php:195)の中に置くので判定は二重になるが、
 * 既存の管理者専用マスタと同じ形にしてある(手本: MeetingPackPolicy / AnnouncementPolicy)。
 * 入口(ミドルウェア)と個々の操作(Policy)で守る層を分ける。
 *
 * ⚠️ この Policy は「状態(draft / published / archived)」を一切見ない。
 * 「下書きからしか公開できない」といった遷移の正しさは Action 側で拒否する。
 * 画面も @can(権限) と @if(状態) を別々に書いており、その分担に揃えてある
 * (resources/views/plan/management/show.blade.php:43-51)。
 */
class PlanPolicy
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
    public function view(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /** 新規作成(フォームの表示と実行の両方に使う) */
    public function create(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /** 基本情報の編集(状態はこのフォームでは変更しない) */
    public function update(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /**
     * 削除。「下書きかつ受講者0名かつプラン履歴0件」という条件は Action 側で判定する
     * (条件の出どころは resources/views/plan/management/show.blade.php:88 の確認ダイアログ)。
     */
    public function delete(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /** 下書き → 公開中 */
    public function publish(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /** 公開中 → アーカイブ */
    public function archive(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /** アーカイブ → 下書き(画面のラベルは「下書きへ戻す」) */
    public function unarchive(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }
}
