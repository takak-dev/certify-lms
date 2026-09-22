<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeetingPackStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 追加面談購入用の SKU マスタ。受講生が dashboard から購入する都度購入型の面談回数パック。
 *
 * draft → published → archived の 3 状態を持ち、公開中の SKU のみ受講生の購入動線に並ぶ。
 * 価格は円単位(unit_amount にそのまま渡す)。Stripe Price ID を事前作成済みの場合は紐付けられるが、
 * 現状は Checkout Session の都度生成(price_data 動的)で運用する。
 *
 * 関連: User(created_by / updated_by) / Payment(このパックの購入履歴)
 */
class MeetingPack extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'description',
        'meeting_count',
        'price',
        'stripe_price_id',
        'status',
        'sort_order',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'status' => MeetingPackStatus::class,
        'meeting_count' => 'integer',
        'price' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * このパックの購入履歴(S-A-03)。
     *
     * ⚠️ このリレーションは支給 Blade が既に前提にしている。
     *    meeting-pack/management/show.blade.php:125 の `$plan->payments->count()` と
     *    :133 の `$plan->payments->isEmpty()` は `class_exists(\App\Models\Payment::class)` で
     *    守られているため、Payment クラスを作った瞬間にガードが外れて呼ばれ始める。
     *    Eloquent は未定義のリレーションを読んでも例外を投げず null を返すので、
     *    ここを書き忘れると「null に対する count()」という原因の分かりにくい 500 になる。
     *
     * 並び順は呼び出し側(UseCases\MeetingPack\ShowAction が直近 20 件)で決める。
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @param Builder<MeetingPack> $query
     *
     * @return Builder<MeetingPack>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', MeetingPackStatus::Published);
    }

    /**
     * @param Builder<MeetingPack> $query
     *
     * @return Builder<MeetingPack>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderByDesc('created_at');
    }
}
