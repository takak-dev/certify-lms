<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeetingStatus;
use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 1on1 面談予約を表す Model。
 *
 * 1 件 = 60 分固定の単発予約で、受講生 / 担当コーチ / 紐づく受講登録の 3 者と、面談回数履歴の
 * 消費トランザクションを参照する。コーチは予約時に自動割当されるため、受講生フローから coach_id を
 * 直接指定することはない(予約処理が自動決定する)。
 *
 * 関連: Enrollment(受講登録) / User(coach / student / canceledBy) / MeetingMemo(1:1) / MeetingQuotaTransaction(消費トランザクション)
 * scope: upcoming / past / forCoach(coach) / forStudent(student)
 */
class Meeting extends Model
{
    /** @use HasFactory<MeetingFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'enrollment_id',
        'coach_id',
        'student_id',
        'scheduled_at',
        'status',
        'topic',
        'canceled_by_user_id',
        'canceled_at',
        'meeting_url_snapshot',
        'completed_at',
        'meeting_quota_transaction_id',
    ];

    protected $casts = [
        'status' => MeetingStatus::class,
        'scheduled_at' => 'datetime',
        'canceled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * 担当コーチ。退会(SoftDelete)後も氏名を表示し続けるため withTrashed で参照する
     * (decisions #46 / #67 / #105)。canceledBy が同じ理由で既に withTrashed を付けている。
     *
     * ⚠️ これが無いと、退会したコーチの面談で `$meeting->coach` が null になり、
     *    一覧・詳細の Blade(`{{ $meeting->coach->name }}`)が 500 になる。実測で確認済み。
     *    coach_id は必須 + restrictOnDelete(物理削除を DB が拒否)なので、
     *    withTrashed を付ければ当事者が取れないことは起こらない。
     *
     * @return BelongsTo<User, $this>
     */
    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id')->withTrashed();
    }

    /**
     * 受講生。withTrashed の理由は coach() と同じ。
     *
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id')->withTrashed();
    }

    /**
     * キャンセル操作の実行者(受講生 or コーチ)。退会等で User が SoftDelete された後も
     * 「誰がキャンセルしたか」を履歴で示せるよう withTrashed で参照する。
     *
     * @return BelongsTo<User, $this>
     */
    public function canceledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'canceled_by_user_id')->withTrashed();
    }

    /**
     * @return HasOne<MeetingMemo, $this>
     */
    public function meetingMemo(): HasOne
    {
        return $this->hasOne(MeetingMemo::class);
    }

    /**
     * @return BelongsTo<MeetingQuotaTransaction, $this>
     */
    public function quotaTransaction(): BelongsTo
    {
        return $this->belongsTo(MeetingQuotaTransaction::class, 'meeting_quota_transaction_id');
    }

    /**
     * 今後の予約(予約済 かつ 開始時刻が未来)に絞る。dashboard / 履歴 upcoming タブで利用。
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->where('status', MeetingStatus::Reserved->value)
            ->where('scheduled_at', '>=', now());
    }

    /**
     * 過去の予約(キャンセル済 or 完了済)に絞る。履歴 past タブで利用。
     */
    public function scopePast(Builder $query): Builder
    {
        return $query->whereIn('status', [
            MeetingStatus::Canceled->value,
            MeetingStatus::Completed->value,
        ]);
    }

    public function scopeForCoach(Builder $query, User $coach): Builder
    {
        return $query->where('coach_id', $coach->id);
    }

    public function scopeForStudent(Builder $query, User $student): Builder
    {
        return $query->where('student_id', $student->id);
    }
}
