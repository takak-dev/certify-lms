<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

/**
 * 個人学習目標の削除ユースケース。
 *
 * ⚠️ 物理削除。EnrollmentGoal は SoftDeletes を使っていないので delete() が行を消す。
 *    原典が「物理削除、履歴は残さない」と明記し、「論理削除 + 復元 UI」をスコープ外に置いている。
 *    誤削除の防止は画面の confirm() が担当する(enrollment-goal/_form.blade.php:102)。
 *
 * 削除ガードは設けない。目標は利用者が自分のために書いた記録で、他のテーブルから
 * 参照されていないため(QuestionCategory\DestroyAction のような使用中チェックは不要)。
 */
final class DestroyAction
{
    public function __invoke(EnrollmentGoal $goal): void
    {
        DB::transaction(fn () => $goal->delete());
    }
}
