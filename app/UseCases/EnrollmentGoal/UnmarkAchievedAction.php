<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

/**
 * 個人学習目標の達成マークを外すユースケース。MarkAchievedAction の対。
 * 手本: app/UseCases/QaThread/UnresolveAction.php。
 *
 * 原典のユーザーストーリー「誤って達成マークしてしまった目標を取り消したい」がこの操作。
 * 達成時刻はクリアする(未達成なのに達成日時が残っている状態を作らない)。
 *
 * 既に未達成の場合は何もしない(冪等)。
 */
final class UnmarkAchievedAction
{
    public function __invoke(EnrollmentGoal $goal): EnrollmentGoal
    {
        if (! $goal->isAchieved()) {
            return $goal;
        }

        return DB::transaction(function () use ($goal): EnrollmentGoal {
            $goal->forceFill(['achieved_at' => null])->save();

            return $goal->refresh();
        });
    }
}
