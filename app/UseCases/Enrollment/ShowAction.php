<?php

declare(strict_types=1);

namespace App\UseCases\Enrollment;

use App\Models\Enrollment;

/**
 * Enrollment 詳細取得 Action。受講生 / コーチ / admin 共通(認可は Controller の Policy で済ませる前提)。
 *
 * 詳細ビューで必要な coaches / 修了証 / 最新の状態遷移ログを eager load する。
 */
final class ShowAction
{
    public function __invoke(Enrollment $enrollment): Enrollment
    {
        return $enrollment->loadMissing([
            'certification.category',
            'certification.coaches',
            'certificate',
            'latestStatusLog.changedBy',
            // 個人目標(S-B-05)。enrollment-goal/_form.blade.php:9 が $enrollment->goals を読む。
            //
            // 並び順(decisions #43)はここで差し込む。手本: app/UseCases/Part/ShowAction.php の
            // 'chapters' => fn ($q) => $q->ordered()。
            //
            // .enrollment まで先読みするのは、Blade が目標ごとに @can を 4 回評価し
            // (enrollment-goal/_form.blade.php:78,86,93,98)、EnrollmentGoalPolicy が
            // $goal->enrollment をたどるため。付けないと目標の件数だけクエリが増える(実測で確認)。
            //
            // ⚠️ 「親は手元にあるのだから setRelation() で配れば 0 クエリで済む」と考えたくなるが、
            //    解除済み(論理削除済み)の受講登録では belongsTo が null を返すことを利用して
            //    操作ボタンを消しているので、親を手で配ると解除済みでもボタンが出てしまう。
            //    (受講解除では目標ごと消えるので通常この状態にはならないが、守りを外す理由にはならない)
            //
            // ⚠️ この 2 行は順番に意味がある。loadMissing は「読み込み済みなら何もしない」ため、
            //    'goals.enrollment' を先に書くと goals が closure 無しで読まれ、並び順が黙って落ちる。
            'goals' => fn ($q) => $q->displayOrder(),
            'goals.enrollment',
        ]);
    }
}
