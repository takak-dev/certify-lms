<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnouncementTargetType;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 管理者が受講生集合へ一斉配信したお知らせ。配信実績そのものを兼ねる。
 *
 * 配信は不可逆(再配信 / 編集 / 取消なし)のため、更新も削除も行わない。
 * `dispatched_count` / `dispatched_at` / `created_by_user_id` はフォームの値ではなく
 * 配信処理がその場で確定させるため、$fillable に含めない
 * (手本: QaThread の status / resolved_at。一括代入で外から差し込まれるのを防ぐ)。
 *
 * 関連: Certification(対象資格) / User(対象受講生) / User(配信した管理者)
 */
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'title',
        'body',
        'target_type',
        'target_certification_id',
        'target_user_id',
    ];

    protected $casts = [
        // 文字列で入っている値を Enum に戻す。これで画面が $announcement->target_type->label() を呼べる
        'target_type' => AnnouncementTargetType::class,
        'dispatched_at' => 'datetime',
        // 件数列は整数で受け取る。既存の件数列も同じ(手本: MeetingPack.php:41)
        'dispatched_count' => 'integer',
    ];

    /**
     * 配信対象の資格。target_type が「資格指定」のときだけ値が入る。
     *
     * 外部キー名を書いていないのは、Eloquent がメソッド名から推測するため
     * (targetCertification → target_certification_id)。今回はそれが実際の列名と一致する。
     *
     * @return BelongsTo<Certification, $this>
     */
    public function targetCertification(): BelongsTo
    {
        return $this->belongsTo(Certification::class);
    }

    /**
     * 配信対象の受講生。target_type が「ユーザー指定」のときだけ値が入る。
     *
     * withTrashed() は、退会(論理削除)後も氏名を表示し続けるため(decisions #67)。
     * 付けないと退会した瞬間にリレーションが null になり、過去の配信実績から宛先が消える。
     *
     * @return BelongsTo<User, $this>
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * 配信した管理者。
     *
     * ⚠️ ここは外部キー名を明示しないと動かない。Eloquent の推測は
     * createdBy → created_by_id だが、実際の列は created_by_user_id のため
     * (手本: Certification.php:62 / MockExam.php:60 / Plan.php:49 も同じ書き方)。
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }
}
