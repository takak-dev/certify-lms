<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EnrollmentNoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 受講登録(Enrollment)配下にコーチ / 管理者が残す業務メモ 1 件を表す Model。
 *
 * 書けるのはコーチ(担当資格のみ)と管理者。編集 / 削除は作成者本人と管理者だけができる。
 * 受講生本人には一覧セクションごと見せない(原典「受講生は閲覧含めすべて拒否」)。
 * 見せない判定は EnrollmentNotePolicy が担当し、Model 側には持たせない。
 *
 * 入力項目は body 1 つだけなので $fillable も body だけ。外部キー(enrollment_id / author_id)は
 * フォームの値ではなくサーバ側で決まるため $fillable に置かず、Action が明示的に代入する。
 * 手本: QaReply.php:27-29 + QaReply\StoreAction.php:29-32(同じ「親 + 投稿者 + 本文」の形)。
 *
 * 削除は物理削除(SoftDeletes は使わない。原典「削除すると履歴は残らない」)。
 *
 * ⚠️ 姉妹機能の個人学習目標(EnrollmentGoal)とは権限が真逆。
 *    目標は受講生本人だけが操作し管理者に特権が無いが、メモはコーチ / 管理者が操作し受講生は閲覧すらできない。
 *
 * 関連: Enrollment(親) / User(作成者)
 */
class EnrollmentNote extends Model
{
    /** @use HasFactory<EnrollmentNoteFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'body',
    ];

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        // ⚠️ withTrashed を付ける。受講解除(親の論理削除)されていても親を引けるようにして、
        //    「解除済みかどうか」は Policy が $enrollment->trashed() で明示的に判定する
        //    (decisions #157)。
        //
        //    付けないと belongsTo が null を返すので、Policy は null 判定だけで済む。実際そう書いていたが、
        //    それは「リレーションがまだ読み込まれていない」ことに依存した判定だった。
        //    setRelation() で親を手で配られると null にならず、管理者が解除済みのメモを
        //    更新できてしまう(実測で確認。decisions #137 違反)。
        //    しかも S-B-05 の Enrollment/ShowAction.php:32-35 には「@can の N+1 を減らすため
        //    setRelation で親を配りたくなる」という誘惑がコメントで戒められており、
        //    いつ誰が踏んでもおかしくない。読み込み方に左右されない形に変えた。
        return $this->belongsTo(Enrollment::class)->withTrashed();
    }

    /**
     * メモを書いた人(コーチ または 管理者)。
     *
     * ⛔ リレーション名 `author` は支給コードが固定している。変えると静かに壊れる。
     *    enrollment-note/_list.blade.php:9 が ->with('author')、:41-42 が $note->author を読む。
     *
     * 退会(論理削除)後も氏名を表示し続けるため withTrashed で参照する(decisions #46。面談1 で確認済み)。
     * これが無いと退会したコーチのメモで $note->author が null になる。
     * 支給 Blade は :42 が `$note->author?->name ?? '不明'` と null を想定した書き方なので 500 にはならないが、
     * 「氏名をそのまま表示し続ける」という決定に反して『不明』と出てしまう。
     * 手本: QaReply.php:45 / Meeting.php:73。
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }
}
