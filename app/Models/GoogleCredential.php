<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GoogleCredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * コーチが任意連携した Google アカウントの OAuth 認証情報(1 ユーザー : 1 連携)を表す Model(S-A-01)。
 *
 * この行が存在する = 連携中。連携解除は行の物理削除で表し、状態列も SoftDeletes も持たない。
 * 支給 Blade が `$user->googleCredential` の有無だけで出し分けているため
 * (settings/_partials/tab-meeting.blade.php:82,87)、解除済みの行を残すと画面が連携中のままになる。
 *
 * ⛔ リレーション名 `googleCredential` は支給コードが固定している。変えると静かに壊れる
 *    (同 Blade:82 が `$user->googleCredential` を読む)。
 * ⛔ プロパティ名 `calendar_id` / `connected_at` も同様(同 Blade:104,107)。
 *
 * ⚠️ トークンは平文で保存する(原典 スコープ外「認証情報の暗号化保存 — 本チケットのスコープ外」)。
 *    本番運用での暗号化推奨は README に明記する(原典が明示的に要求している)。
 *
 * 関連: User(所有者。コーチのみ)
 */
class GoogleCredential extends Model
{
    /** @use HasFactory<GoogleCredentialFactory> */
    use HasFactory, HasUlids;

    /**
     * 全列を $fillable に置く。この Model の値はすべて OAuth のレスポンスとサーバ側の判断から決まり、
     * ユーザーがフォームから送る値は 1 つも無いため、外部キーだけを除外する理由が無い。
     *
     * 手本: Invitation.php:29-38(外部キーの user_id / invited_by_user_id も含めて全列を $fillable に
     * 置いている先例)。⚠️ あちらは email / role が管理者のフォーム入力なので値の出どころは混在するが、
     * こちらは混在が無いぶん線を引く理由がさらに無い。
     * ⚠️ EnrollmentNote.php:36-38 は逆に外部キーを外しているが、あちらは「フォームの値(body)と
     *    サーバが決める値(FK)が混在する」ので線を引いていた。ここは混在しない。
     */
    protected $fillable = [
        'user_id',
        'calendar_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'connected_at',
    ];

    /**
     * トークンは秘密情報なので配列 / JSON 化から除外する。
     * 手本: User.php:51-54(password / remember_token を同じ理由で隠している)。
     *
     * この Model を直接 JSON で返す動線は今のところ無いが、将来 toArray() / toJson() を
     * 経由したときにトークンが漏れる口を先に塞いでおく。
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * ⚠️ connected_at の datetime cast は必須。支給 Blade が `connected_at?->format('Y-m-d H:i')` と
     *    Carbon のメソッドを呼んでいる(tab-meeting.blade.php:107)。cast が無いと文字列のまま渡り、
     *    「Call to a member function format() on string」で画面が 500 になる。
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'connected_at' => 'datetime',
    ];

    /**
     * 連携しているユーザー(コーチ)。
     *
     * ⚠️ withTrashed は付けない。手本は CoachAvailability.php:46 —— 同じ面談設定タブにある
     *    「コーチ本人の設定」で、あちらも付けていない。
     *
     *    このプロジェクトの belongsTo(User) を実測すると 35 件(この行自身を含む)で、
     *    withTrashed が付くのは 12 件。いずれも「退会後も氏名を表示し続ける」もの
     *    (Meeting.coach / EnrollmentNote.author / QaThread.user /
     *    UserStatusLog.changedBy / UserPlanLog.changedBy など。decisions #46)。
     *    ⚠️ 「changedBy なら付く」ではない —— EnrollmentStatusLog.changedBy には付いていない。
     *    残り 23 件は氏名を画面に出さないもので、「本人しか見ない持ち物」
     *    (Enrollment / Certificate / LearningSession / ChatMember)のほか、
     *    createdBy / updatedBy のような監査列も含む。
     *    連携情報の所有者名を画面に出す動線は無い(支給 Blade は $user->googleCredential の
     *    順方向しか読まない)ので後者に揃える。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
