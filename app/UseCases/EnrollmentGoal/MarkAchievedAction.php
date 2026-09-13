<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

/**
 * 個人学習目標に達成マークを付けるユースケース。手本: app/UseCases/QaThread/ResolveAction.php。
 *
 * 達成した時刻を achieved_at に残す。状態ログテーブルは設けない
 * (原典スコープ外「目標の状態遷移履歴(達成 → 解除 → 達成のログ)— 達成日時1つでの状態管理のみ」。
 *  掲示板の解決マークで同じ判断をした decisions #69 と揃う)。
 *
 * 既に達成済みの場合は何もしない(冪等)。二重送信や戻るボタンでの再送で achieved_at が
 * 上書きされ、達成した時刻が後ろにずれるのを防ぐ。
 */
final class MarkAchievedAction
{
    public function __invoke(EnrollmentGoal $goal): EnrollmentGoal
    {
        if ($goal->isAchieved()) {
            return $goal;
        }

        return DB::transaction(function () use ($goal): EnrollmentGoal {
            // update() ではなく forceFill() を使う。achieved_at は $fillable に入れていないため
            // (フォーム経由での書き込みを禁じている)、update() では何も書き込まれない。
            // ここは「フォームの値」ではなくサーバが決めた時刻を入れる場所なので force してよい
            $goal->forceFill(['achieved_at' => now()])->save();

            return $goal->refresh();
        });
    }
}
