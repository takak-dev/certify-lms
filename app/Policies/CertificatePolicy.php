<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Certificate;
use App\Models\User;

/**
 * 修了証の認可ルール(S-A-04)。
 *
 * - admin: 全件ダウンロード可
 * - coach: 担当資格(certification_coach_assignments が active)配下の修了証のみ可
 * - student: 本人の修了証のみ可
 *
 * ⚠️ 受講ステータスは見ない。修了(passed) / 退会前でも本人はダウンロードできる
 *    ——修了証は本人の永続資産であるため(原典のユーザーストーリー)。
 *    同じ理由でルートに `active-learning` を付けない
 *    (`app/Http/Middleware/EnsureActiveLearning.php:16` が「修了証 PDF DL」を名指しで除外している)。
 */
class CertificatePolicy
{
    public function download(User $auth, Certificate $certificate): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            UserRole::Coach => $this->assignedCoach($auth, $certificate),
            // 本人判定は certificates.user_id を直接見る。enrollment 経由にしないのは、
            // Enrollment が SoftDeletes(app/Models/Enrollment.php:33)を使っており、
            // 親が論理削除されるとリレーションが null になって本人まで弾かれるため。
            UserRole::Student => $certificate->user_id === $auth->id,
            default => false,
        };
    }

    /**
     * この修了証の資格を、そのコーチが現在担当しているか。
     *
     * `coaches()` は unassigned_at IS NULL の active 行だけを返す(実装は
     * app/Models/Certification.php:89 の `wherePivot('unassigned_at', null)`)ため、
     * 担当を外れたコーチはここで false になる。手本: MockExamPolicy::assignedCoach()
     */
    private function assignedCoach(User $coach, Certificate $certificate): bool
    {
        return $certificate->certification->coaches()->where('users.id', $coach->id)->exists();
    }
}
