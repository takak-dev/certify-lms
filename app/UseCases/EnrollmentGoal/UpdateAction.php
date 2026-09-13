<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

/**
 * 個人学習目標の更新ユースケース。タイトル / 目標期日 / 詳細 の3つだけを書き換える。
 *
 * 達成状態(achieved_at)には触れない。達成マーク / 解除は MarkAchievedAction /
 * UnmarkAchievedAction の担当で、$validated にも achieved_at は含まれない。
 *
 * 手本: app/UseCases/QuestionCategory/UpdateAction.php。
 */
final class UpdateAction
{
    /**
     * @param array{title: string, target_date?: ?string, description?: ?string} $validated EnrollmentGoal/UpdateRequest::rules() で検証済
     */
    public function __invoke(EnrollmentGoal $goal, array $validated): EnrollmentGoal
    {
        return DB::transaction(function () use ($goal, $validated): EnrollmentGoal {
            $goal->update($validated);

            // fresh() は DB から読み直した別インスタンスを返す。
            // 呼び出し側が「保存後の確定値」を受け取れるようにする(手本と同じ)
            return $goal->fresh();
        });
    }
}
