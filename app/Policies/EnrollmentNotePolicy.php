<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;

/**
 * 受講生メモ(EnrollmentNote)に対する認可ポリシー。
 * 手本: MockExamPolicy(「担当資格のコーチ + 管理者」という同じ形の判定)。
 *
 * ⚠️ 姉妹チケット S-B-05(個人学習目標)とは権限が真逆。流用するときは必ず書き換えること。
 *    - 目標: 受講生本人だけが操作でき、コーチ / 管理者は閲覧のみ(管理者に特権が無い)
 *    - メモ: コーチ / 管理者が操作し、受講生には閲覧すらさせない(管理者に越境権限がある)
 *
 * ⛔ viewAny は原典の HTTP 表に出てこないが、支給 Blade が要求するので必須。
 *    enrollment/show.blade.php:251 が @can('viewAny', [EnrollmentNote::class, $enrollment]) で
 *    メモのカードごと囲んでいる。これを作らないとカードが永久に出ない(Gate は Policy の無い
 *    ability を既定で拒否する。実測で確認)。
 *
 * 「読める」と「書き換えられる」の分担に注意(支給 Blade の作りから決まる)。
 * - viewAny … カードごと出す / 出さない。**読めるかどうかはこれだけで決まる**
 * - update / delete … 一覧の中のボタンだけを出し分ける(enrollment-note/_list.blade.php:46,51 が
 *   囲んでいるのはボタンだけで、行と本文は @can の外)。false にしても他コーチのメモは読めるまま。
 *   原典「他コーチのメモは閲覧のみ」はこの形で実現される。
 */
class EnrollmentNotePolicy
{
    /**
     * メモのカードを表示してよいか。判定対象は親の受講登録。
     */
    public function viewAny(User $user, Enrollment $enrollment): bool
    {
        // 受講解除(親の論理削除)済みでは読めない(decisions #47 / #137。面談1・面談2 で確定)。
        //
        // ⚠️ この判定はここにしか置けない。受講登録詳細は ->withTrashed() 付きで開けるため
        //    (enrollments.show のルート定義に ->withTrashed() が付いている)、
        //    解除済みでも $enrollment は取れてしまう。さらに支給 Blade は
        //    $enrollment->notes() を自前で発行するので(enrollment-note/_list.blade.php:9)、
        //    子側にスコープを足しても効かない。カードごと消すのが唯一の実現方法。
        //
        // ⚠️ decisions #47 の根拠欄にあった「親経由でしか到達しないので自動的に見えなくなる」は誤りで、
        //    2026-09-13 に訂正済み。実装が要る。
        if ($enrollment->trashed()) {
            return false;
        }

        return $this->canAccessEnrollment($user, $enrollment);
    }

    /**
     * メモを追加できるか。判定対象は親の受講登録(まだメモの行が無いため)。
     *
     * enrollment-note/_list.blade.php:15 が @can('create', [EnrollmentNote::class, $enrollment]) で呼ぶ。
     */
    public function create(User $user, Enrollment $enrollment): bool
    {
        // 解除済みには書けない(decisions #137)。
        //
        // ⚠️ create は親インスタンスを画面から直接受け取るため、論理削除済みでも中身が読めてしまう。
        //    この判定が無いと、解除済みの詳細に追加フォームだけが出て、送信すると 404 になる
        //    (S-B-05 で実際に踏んだ穴。decisions #134)。
        //    送信の拒否はルート層も担当する(store の {enrollment} に ->withTrashed() を付けていないため
        //    モデルバインディングが解決せず 404)。ここが担うのは画面の出し分け。
        if ($enrollment->trashed()) {
            return false;
        }

        return $this->canAccessEnrollment($user, $enrollment);
    }

    /**
     * 本文を更新できるか。enrollment-note/_list.blade.php:46 と編集ページの認可に使う。
     */
    public function update(User $user, EnrollmentNote $note): bool
    {
        return $this->canManageNote($user, $note);
    }

    /**
     * 削除できるか。enrollment-note/_list.blade.php:51。
     */
    public function delete(User $user, EnrollmentNote $note): bool
    {
        return $this->canManageNote($user, $note);
    }

    /**
     * 「この受講登録のメモ欄に関われる人か」だけを見る判定。viewAny と create の共通部分。
     *
     * 受講生は role が一致しないので default に落ちて false になる
     * (原典「受講生は閲覧含めすべて拒否」)。担当外コーチも isAssignedCoach が false を返す。
     *
     * ⚠️ viewAny / create から共通化しているのは「関われる人か」だけで、ability 自体は分けたまま。
     *    片方にだけ効く条件を後から足したとき(例: 1 受講登録あたりの上限件数)、
     *    もう片方まで巻き添えで拒否されるのを避けるため。手本: EnrollmentGoalPolicy::isOwnerStudent()。
     */
    private function canAccessEnrollment(User $user, Enrollment $enrollment): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Coach => $this->isAssignedCoach($user, $enrollment),
            default => false,
        };
    }

    /**
     * 既存のメモ 1 件を書き換えてよいか。update と delete の共通部分。
     *
     * 原典の権限は「コーチは自分が作成したメモのみ / 管理者は任意のメモ」。
     */
    private function canManageNote(User $user, EnrollmentNote $note): bool
    {
        $enrollment = $note->enrollment;

        // ⚠️ これが「解除済みでは書けない」(decisions #137)の唯一の関門。メモの更新 / 削除の URL は
        //    /enrollment-notes/{note} で親を含まないため、ルート層では弾けない(store とはここが違う)。
        //
        // ⚠️ trashed() で明示的に見る(decisions #157)。当初は「belongsTo が論理削除済みの親に
        //    null を返す」ことに頼って null 判定だけを書いていたが、それは
        //    「リレーションがまだ読み込まれていない」ことに依存した判定で、setRelation() で
        //    親を手で配られると素通りした(実測で確認)。EnrollmentNote::enrollment() に
        //    withTrashed を付け、ここで状態を見る形に変えた。viewAny / create と同じ書き方になる。
        //
        // ⚠️ null 判定も残す。author_id と違い enrollment_id は必須 + restrictOnDelete なので
        //    通常 null にはならないが、型として ?Enrollment である以上は落とす先が要る。
        //
        // ⚠️ 管理者も例外にしない。#137 は「解除済みでは読むことも書くこともできない」と役割を分けずに
        //    決めており、viewAny も解除済みを一律で false にしているため、こちらだけ通すと
        //    「カードは見えないのに URL を打てば直せる」という食い違いになる。
        if ($enrollment === null || $enrollment->trashed()) {
            return false;
        }

        return match ($user->role) {
            // 管理者は越境して全メモを操作できる(原典「運用上の不適切記述の是正やコーチ離任時のメモ管理」)。
            // ⚠️ S-B-05 の目標はここが false。管理者の扱いが姉妹機能と逆であることに注意。
            UserRole::Admin => true,

            // コーチは「自分が書いたメモ」かつ「いまも担当している資格」のときだけ操作できる。
            //
            // ⚠️ 後半の担当条件は暫定判断(面談3 Q53 で確認予定)。原典が2か所で食い違っているため
            //    厳しい側に倒した——HTTP 表の認可欄は「作成者本人 / 管理者」(担当条件なし)だが、
            //    アクセス制御は「担当していない資格の受講登録に対するコーチのメモ操作は拒否」と書く。
            //    担当を外れたコーチは受講登録詳細そのものが 403 で開けない(EnrollmentPolicy::view)のに、
            //    メモの URL だけ直接届いてしまうため、画面から辿れない操作は塞ぐ側を選んだ。
            //    後から緩めるのは安全(&& を 1 つ外すだけ)。
            //
            // ⚠️ 支給コードの前例は 2 系統に割れている。MeetingPolicy.php:54(コーチの面談メモ)は
            //    作成者だけを見て担当かは見ない。QaReplyPolicy.php:43-50 は「作成者かつ現在も閲覧可」。
            //    後者に揃えた。
            UserRole::Coach => $note->author_id === $user->id
                && $this->isAssignedCoach($user, $enrollment),

            default => false,
        };
    }

    /**
     * その資格の担当コーチか。担当は Enrollment ではなく Certification に紐づく
     * (certification_coach_assignments 経由の N:N)。
     *
     * 判定の中身は EnrollmentPolicy::isAssignedCoach() と同じ(同じ受講登録から同じ判定をしている)。
     * 引数の順番だけあちらと逆だが、これは MockExamPolicy::assignedCoach(User, Certification) に揃えたもので、
     * 本 Policy の private メソッドがすべて User を第1引数に取る形と一致させている。
     * loadMissing で読み込み済みなら再クエリしない。
     */
    private function isAssignedCoach(User $coach, Enrollment $enrollment): bool
    {
        $enrollment->loadMissing('certification.coaches');

        return $enrollment->certification?->coaches->contains('id', $coach->id) ?? false;
    }
}
