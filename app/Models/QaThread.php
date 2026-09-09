<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use Database\Factories\QaThreadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 質問掲示板のスレッド(質問1件)を表す Model。投稿できるのは受講生のみ。
 *
 * 資格に紐づくが受講登録は前提にしない(受講していない資格にも質問できる)。
 * 解決済 / 未解決の切り替えは投稿者本人のみで、専用エンドポイント(resolve / unresolve)が担当するため
 * `status` / `resolved_at` は $fillable に含めない(フォーム経由での一括代入を禁止する)。
 * 削除は物理削除(SoftDeletes は使わない)。
 *
 * 関連: User(投稿者) / Certification(質問対象の資格) / QaReply(配下の回答)
 */
class QaThread extends Model
{
    /** @use HasFactory<QaThreadFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'certification_id',
        'title',
        'body',
    ];

    protected $casts = [
        'status' => QaThreadStatus::class,
        'resolved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        // 退会(論理削除)後も氏名を表示し続ける(decisions #67)。手本: Invitation.php:50 / Meeting.php:84
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Certification, $this>
     */
    public function certification(): BelongsTo
    {
        return $this->belongsTo(Certification::class);
    }

    /**
     * 配下の回答。詳細画面は投稿順(古い順)に並べる。
     *
     * @return HasMany<QaReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(QaReply::class);
    }

    /**
     * 閲覧者のロールに応じて一覧の対象行を絞る。手本: `Certification::scopeForUser()`。
     *
     * - admin: 全件(公開停止中の資格のスレッドも含む)
     * - student: 公開中の資格のスレッドのみ
     * - coach: 公開中 かつ 担当資格(certification_coach_assignments)のスレッドのみ(decisions #40)
     *
     * @param Builder<QaThread> $query
     *
     * @return Builder<QaThread>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            UserRole::Admin => $query,
            UserRole::Student => $query->wherePublishedCertification(),
            UserRole::Coach => $query
                ->wherePublishedCertification()
                ->whereHas('certification.coaches', fn (Builder $q) => $q->where('users.id', $user->id)),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * 資格が公開中のスレッドだけに絞る。
     *
     * @param Builder<QaThread> $query
     *
     * @return Builder<QaThread>
     */
    public function scopeWherePublishedCertification(Builder $query): Builder
    {
        return $query->whereHas(
            'certification',
            fn (Builder $q) => $q->where('status', CertificationStatus::Published->value)
        );
    }

    /**
     * キーワードの部分一致。検索対象は本文のみ(decisions #63。画面の文言が「質問の本文を検索...」)。
     *
     * @param Builder<QaThread> $query
     *
     * @return Builder<QaThread>
     */
    public function scopeKeyword(Builder $query, ?string $keyword): Builder
    {
        if ($keyword === null || $keyword === '') {
            return $query;
        }

        return $query->where('body', 'LIKE', '%'.$keyword.'%');
    }
}
