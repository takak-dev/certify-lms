<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MockExamSessionStatus;
use Database\Factories\MockExamSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 受講生が公開模試を受験するときに生成される受験セッションを表す Model。
 *
 * 関連: MockExam(模試マスタ) / Enrollment(受講登録) / User(受講生、非正規化) / MockExamAnswer(個別解答ログ)
 * TermJudgementService(学習ターム判定) と CompletionEligibilityService(修了判定) の起点となる。
 * 時間制限機能は持たない。受講生はいつでも明示提出で採点を受ける。
 *
 * generated_question_ids: 受験開始時点の MockExamQuestion.id 配列スナップショット。
 *   マスタ側の問題追加・削除に左右されず、当該セッションの出題範囲を保証する。
 *
 * scope: graded() / canceled() / forUser(User) / forEnrollment(Enrollment)
 */
class MockExamSession extends Model
{
    /** @use HasFactory<MockExamSessionFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'mock_exam_id',
        'enrollment_id',
        'user_id',
        'status',
        'generated_question_ids',
        'total_questions',
        'passing_score_snapshot',
        'started_at',
        'submitted_at',
        'graded_at',
        'canceled_at',
        'total_correct',
        'score_percentage',
        'pass',
    ];

    protected $casts = [
        'status' => MockExamSessionStatus::class,
        'generated_question_ids' => 'array',
        'total_questions' => 'integer',
        'passing_score_snapshot' => 'integer',
        'total_correct' => 'integer',
        'score_percentage' => 'decimal:2',
        'pass' => 'boolean',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<MockExam, $this>
     */
    public function mockExam(): BelongsTo
    {
        return $this->belongsTo(MockExam::class);
    }

    /**
     * この受験の受講登録。受講解除(SoftDelete)後も引けるように withTrashed で参照する。
     *
     * ⚠️ 受講解除(Enrollment\DestroyAction)は受講登録を論理削除するだけで、受験セッションは残る。
     *    これが無いと解除済みの受講登録のセッションで `$session->enrollment` が null になり、
     *    結果画面(MockExamSessionController::show())と管理者・コーチの受験詳細(Monitor\ShowAction)が
     *    WeaknessAnalysisService::getPassProbabilityBand(Enrollment) に null を渡して 500 になる。実測で確認済み。
     *    画面側もこの受講登録でリンクを作る(mock-exam-session/*.blade.php)。
     *    enrollment_id は必須 + restrictOnDelete(物理削除を DB が拒否)なので、withTrashed を付ければ必ず引ける。
     *    書き方は ChatRoom::enrollment() / Meeting::enrollment() と同じ。
     *
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<MockExamAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(MockExamAnswer::class);
    }

    public function scopeGraded(Builder $query): Builder
    {
        return $query->where('status', MockExamSessionStatus::Graded->value);
    }

    public function scopeCanceled(Builder $query): Builder
    {
        return $query->where('status', MockExamSessionStatus::Canceled->value);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeForEnrollment(Builder $query, Enrollment $enrollment): Builder
    {
        return $query->where('enrollment_id', $enrollment->id);
    }
}
