<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EnrollmentGoalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 受講登録(Enrollment)配下の個人学習目標 1 件を表す Model。立てられるのは受講生本人のみで、
 * 担当コーチ / 管理者は閲覧だけできる(介入しない)。
 *
 * 達成状態は achieved_at 1 本で表す(NULL = 未達成 / 日時あり = 達成済)。
 * 達成マーク / 解除は専用エンドポイント(markAchieved / unmarkAchieved)が担当するため、
 * `achieved_at` は $fillable に含めない(フォーム経由での一括代入を禁止する)。手本: QaThread.php:23。
 * 外部キーの enrollment_id も同じ理由で含めない — 親は URL から決まるので、Action は
 * $enrollment->goals()->create() とリレーション経由で作り、外部キーは Laravel に入れさせる
 * (HasOneOrMany::create() が $fillable を通さず setAttribute する)。
 * 手本: QaReply.php:27-29(外部キーを $fillable に置かない) /
 *       QuestionCategory\StoreAction.php(親リレーション経由で create する形)。
 *
 * 削除は物理削除(SoftDeletes は使わない。原典スコープ外「論理削除 + 復元 UI」)。
 *
 * 関連: Enrollment(親)
 */
class EnrollmentGoal extends Model
{
    /** @use HasFactory<EnrollmentGoalFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'title',
        'description',
        'target_date',
    ];

    protected $casts = [
        // date にすると Carbon の日付(時刻なし)になる。Blade が日付として ->format() を呼ぶために必要
        // (enrollment-goal/_form.blade.php:69 は 'Y-m-d' /
        //  dashboard/_partials/student/goal-timeline.blade.php:48 は 'Y/m/d' と書式が違う)
        'target_date' => 'date',
        'achieved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * 達成済みかどうか。
     *
     * ⛔ このメソッド名は支給コードが固定している。
     * dashboard/_partials/student/goal-timeline.blade.php:22 が $goal->isAchieved() を呼ぶ。
     * (受講登録詳細の enrollment-goal/_form.blade.php:54 は achieved_at を直接読むので、両方の形が要る)
     */
    public function isAchieved(): bool
    {
        return $this->achieved_at !== null;
    }

    /**
     * 一覧の表示順(decisions #43)。
     * 未達成を先に → 目標期日が近い順(期日なしは末尾) → 同期日は作成日の新しい順。
     *
     * ⛔ このスコープ名は支給コードが固定している。
     * app/UseCases/Dashboard/FetchStudentDashboardAction.php:299 が ->displayOrder() を呼ぶ。
     *
     * 「期日なしを末尾へ」は列の値では表せないので CASE 式で 0 / 1 に変換して並べる。
     * 手本: app/UseCases/Enrollment/IndexAction.php の
     * orderByRaw('CASE WHEN exam_date IS NULL THEN 1 ELSE 0 END')(NULLS LAST が同じ形)。
     *
     * @param Builder<EnrollmentGoal> $query
     *
     * @return Builder<EnrollmentGoal>
     */
    public function scopeDisplayOrder(Builder $query): Builder
    {
        return $query
            // 未達成(achieved_at が NULL)を 0 に倒して先頭へ
            ->orderByRaw('CASE WHEN achieved_at IS NULL THEN 0 ELSE 1 END')
            // 期日なし(target_date が NULL)を 1 に倒して末尾へ
            ->orderByRaw('CASE WHEN target_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('target_date')
            ->orderByDesc('created_at');
    }
}
