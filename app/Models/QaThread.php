<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QaThreadStatus;
use Database\Factories\QaThreadFactory;
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
     * 配下の回答。詳細画面は投稿順(古い順)に並べる。
     *
     * @return HasMany<QaReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(QaReply::class);
    }
}
