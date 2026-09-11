<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * お知らせ 1 件の配信対象を解決するサービス。
 *
 * Action ではなく Service に置くのは、複数 Entity(Announcement / User / Enrollment)にまたがる
 * 判定ロジックだから(ONBOARDING.md:66 の責務表)。状態は変えず、対象の集合を返すだけ。
 * 手本: `CompletionEligibilityService`——修了可否を判定して返すだけで、状態遷移は呼び出し側の Action が行う。
 *
 * `Announcement\StoreAction`(実際の配信)と `AnnouncementSeeder`(初期データ)の両方から使う。
 * 2 箇所で書くと、片方だけ条件が変わったときに「履歴の件数と受け取った人が合わない」
 * 初期データができてしまう。
 *
 * 3 タイプに共通する土台は `User::scopeInProgressStudents()`——受講中の受講生のみ。
 * コーチ・管理者・修了者・退会者はここで落ちる
 * (decisions #34 / #76、原典スコープ外「コーチ向けの配信」)。
 *
 * 引数は未保存の Announcement でもよい。呼び出し側は先に配信件数を知る必要があるため。
 */
final class AnnouncementRecipientService
{
    /**
     * @return Collection<int, User>
     */
    public function resolve(Announcement $announcement): Collection
    {
        $query = User::query()->inProgressStudents();

        return match ($announcement->target_type) {
            AnnouncementTargetType::AllStudents => $query->get(),

            // その資格に受講登録がある受講生。受講登録の状態では絞らない(decisions #91)。
            // whereHas は「条件を満たす関連が 1 件以上あるか」を副問い合わせで見る。
            // 論理削除された受講登録は Enrollment の SoftDeletes によって自動的に除外される
            AnnouncementTargetType::Certification => $query
                ->whereHas('enrollments', fn ($q) => $q->where(
                    'certification_id',
                    $announcement->target_certification_id,
                ))
                ->get(),

            // 指定 1 名。土台の絞り込みを通すので、受講中の受講生でなければ 0 件になる
            // (decisions #48。FormRequest でも弾いているため通常ここには来ない)
            AnnouncementTargetType::User => $query
                ->whereKey($announcement->target_user_id)
                ->get(),
        };
    }
}
