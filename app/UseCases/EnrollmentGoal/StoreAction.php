<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

/**
 * 個人学習目標の追加ユースケース。親の受講登録配下に紐付けて INSERT する。
 *
 * 手本: app/UseCases/QuestionCategory/StoreAction.php(親リレーション経由で create する形)。
 * 親はフォームの値ではなく URL から決まるので、$validated には enrollment_id が含まれない
 * (EnrollmentGoal/StoreRequest::rules() に無い)。
 */
final class StoreAction
{
    /**
     * @param array{title: string, target_date?: ?string, description?: ?string} $validated EnrollmentGoal/StoreRequest::rules() で検証済
     */
    public function __invoke(Enrollment $enrollment, array $validated): EnrollmentGoal
    {
        // goals() リレーション経由で作ると enrollment_id が自動で入る。
        // Model の $fillable に enrollment_id を置かなくて済むのがこの書き方の利点
        return DB::transaction(fn (): EnrollmentGoal => $enrollment->goals()->create($validated));
    }
}
