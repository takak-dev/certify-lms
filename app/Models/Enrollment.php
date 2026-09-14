<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnrollmentStatus;
use App\Enums\TermType;
use App\Enums\UserRole;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 受講生 × 資格の受講登録を表す Model。1 受講生は複数資格を同時受講可。
 *
 * 担当コーチは Enrollment に直接紐づかず、Certification 経由(certification_coach_assignments、資格 × N コーチ N:N)
 * で参照する。修了は受講生「修了証を受け取る」自己発火で即時 passed 遷移し、admin 承認フローは持たない。
 *
 * 関連: User(受講生) / Certification / Certificate(発行済修了証) / EnrollmentStatusLog / MockExamSession
 * 逆リレーション: defaultedByUser(受講生がデフォルト資格として指している場合のみ存在)
 * scope: learning() / passed() / failed() / forUser(User)(admin = 全件 / coach = 担当資格の Enrollment / student = 自分の Enrollment)
 */
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'certification_id',
        'exam_date',
        'status',
        'current_term',
        'passed_at',
    ];

    protected $casts = [
        'status' => EnrollmentStatus::class,
        'current_term' => TermType::class,
        'exam_date' => 'date',
        'passed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Certification, $this>
     */
    public function certification(): BelongsTo
    {
        return $this->belongsTo(Certification::class);
    }

    /**
     * @return HasOne<Certificate, $this>
     */
    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    /**
     * @return HasMany<EnrollmentStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(EnrollmentStatusLog::class);
    }

    /**
     * 最新の状態遷移ログ 1 件のみ。一覧で「直近の遷移理由」を表示するための eager load 用途。
     *
     * @return HasOne<EnrollmentStatusLog, $this>
     */
    public function latestStatusLog(): HasOne
    {
        return $this->hasOne(EnrollmentStatusLog::class)->latestOfMany('changed_at');
    }

    /**
     * @return HasMany<MockExamSession, $this>
     */
    public function mockExamSessions(): HasMany
    {
        return $this->hasMany(MockExamSession::class);
    }

    /**
     * 本受講登録をデフォルト資格として指している受講生(0 または 1 件)。
     * Enrollment は単一受講生に属するため、`defaultedByUser` も 1 件以下となる。
     *
     * @return HasOne<User, $this>
     */
    public function defaultedByUser(): HasOne
    {
        return $this->hasOne(User::class, 'default_enrollment_id', 'id');
    }

    /**
     * 受講登録に紐づく chat ルーム(1 Enrollment = 1 ChatRoom、受講登録時に eager 生成される)。
     *
     * @return HasOne<ChatRoom, $this>
     */
    public function chatRoom(): HasOne
    {
        return $this->hasOne(ChatRoom::class);
    }

    /**
     * @return HasMany<SectionProgress, $this>
     */
    public function sectionProgresses(): HasMany
    {
        return $this->hasMany(SectionProgress::class);
    }

    /**
     * @return HasMany<LearningSession, $this>
     */
    public function learningSessions(): HasMany
    {
        return $this->hasMany(LearningSession::class);
    }

    /**
     * @return HasOne<LearningHourTarget, $this>
     */
    public function learningHourTarget(): HasOne
    {
        return $this->hasOne(LearningHourTarget::class);
    }

    /**
     * 配下の個人学習目標(S-B-05)。
     *
     * ⛔ リレーション名 `goals` は支給コードが 2 箇所から固定している。変えると静かに壊れる。
     * - enrollment-goal/_form.blade.php:9 が $enrollment->goals を読む
     * - enrollment/_partials/student-index.blade.php:109 が $enrollment->goals_count を読む
     *   (これは withCount('goals') が自動で付ける属性名。リレーション名を変えると名前も変わる)
     *
     * 並び順(decisions #43 / #152)はここでは与えない。読み出す Action 側で
     * with(['goals' => fn ($q) => $q->displayOrder()]) と差し込む。
     * eager load の closure で与えるのが本リポジトリの多数派(手本: app/UseCases/Part/ShowAction.php の
     * 'chapters' => fn ($q) => $q->ordered())。リレーション定義に並び順を持つ例は
     * User::switchableEnrollments() の 1 件だけで、あちらは「1 リクエスト内で複数描画されても
     * キャッシュが効く」ことを狙った選択。目標は 1 画面 1 回しか描画しないので当てはまらない。
     *
     * @return HasMany<EnrollmentGoal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(EnrollmentGoal::class);
    }

    /**
     * 配下のコーチメモ(S-B-07)。受講生本人には見せない業務記録。
     *
     * ⛔ リレーション名 `notes` は支給コードが固定している。しかも呼び方が goals と違う。
     *    enrollment-note/_list.blade.php:9 が Blade の中で
     *    $enrollment->notes()->with('author')->orderByDesc('created_at')->get() と
     *    クエリごと組み立てる。**このリレーションが無いと受講登録詳細が即エラーになる。**
     *
     * 並び順も eager load もすべて Blade 側が指定しているため、ここでも Action 側でも何も足さない
     * (目標は with(['goals' => fn ($q) => $q->displayOrder()]) を Enrollment/ShowAction に置いたが、
     *  メモは置く場所が無い。同じ受講登録詳細でも読み方が違う点に注意)。
     *
     * @return HasMany<EnrollmentNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(EnrollmentNote::class);
    }

    public function scopeLearning(Builder $query): Builder
    {
        return $query->where('status', EnrollmentStatus::Learning->value);
    }

    public function scopePassed(Builder $query): Builder
    {
        return $query->where('status', EnrollmentStatus::Passed->value);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', EnrollmentStatus::Failed->value);
    }

    /**
     * 操作者ロールに応じて表示行を絞り込む scope。
     *
     * - admin: 全件
     * - coach: 自分が担当として割り当てられた資格に属する Enrollment のみ
     * - student: 自分の Enrollment のみ (user_id = self.id)
     * - その他: 空集合
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            UserRole::Admin => $query,
            UserRole::Coach => $query->whereHas('certification', fn (Builder $q) => $q->assignedTo($user)),
            UserRole::Student => $query->where('user_id', $user->id),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
