<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\QaReplyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 質問掲示板のスレッドに付く回答1件を表す Model。投稿できるのは受講生とコーチ(管理者は投稿できない)。
 *
 * 編集 / 削除は投稿者本人のみ。管理者はモデレーションとして削除だけ行える。
 * 親スレッドの削除時は DB の外部キー(cascade)で連動削除されるため、Action 側で個別に消さない。
 * 削除は物理削除(SoftDeletes は使わない)。
 *
 * 関連: QaThread(親スレッド) / User(投稿者)
 */
class QaReply extends Model
{
    /** @use HasFactory<QaReplyFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'body',
    ];

    /**
     * @return BelongsTo<QaThread, $this>
     */
    public function qaThread(): BelongsTo
    {
        return $this->belongsTo(QaThread::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        // 退会(論理削除)後も氏名を表示し続ける(decisions #67)。手本: Invitation.php:50 / Meeting.php:84
        return $this->belongsTo(User::class)->withTrashed();
    }
}
