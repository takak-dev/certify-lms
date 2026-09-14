<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;

/**
 * 個人学習目標(EnrollmentGoal)に対する認可ポリシー。手本: LearningHourTargetPolicy
 * (同じ「受講生が受講登録の配下に置く目標」)。
 *
 * ⚠️ 操作できるのは受講生本人だけ。担当コーチも管理者も操作できない(原典「介入はしない」)。
 *    管理者に特権が無い点が他の Policy と違う。姉妹チケット S-B-07(受講生メモ)は権限が真逆
 *    (コーチ・管理者が書き、受講生には見せない)なので、流用するときは必ず書き換えること。
 *
 * 閲覧(一覧表示)の ability はここに置かない。受講登録詳細を開ける人 ＝ 目標一覧を見られる人で、
 * 支給 Blade も @can を挟んでいない(enrollment/show.blade.php:225 は @if (Route::has(...)) だけ)。
 *
 * 状態は一切見ない。それぞれ別の層が担当する(S-B-03 で確認した「Policy は権限、状態は Action」)。
 * - 修了済 / 退会済 / 招待中 … ルートの active-learning ミドルウェアが弾く
 * - 解除済み(親の論理削除) … 下の isOwner() が null を受けて false になる。create だけは別（下記）
 * - 達成済みかどうか       … 見ない。達成済みの目標も編集・削除できる
 */
class EnrollmentGoalPolicy
{
    /**
     * 目標を追加できるか。判定対象は親の受講登録(まだ目標の行が無いため)。
     *
     * enrollment-goal/_form.blade.php:13 が @can('create', [EnrollmentGoal::class, $enrollment]) で呼ぶ。
     */
    public function create(User $user, Enrollment $enrollment): bool
    {
        // 受講解除(親の論理削除)済みには追加させない(decisions #134)。
        //
        // ⚠️ 操作系 5 つのうち、この判定が要るのは create だけ。理由は「持ち主の調べ方」が違うから。
        //    - update / delete / markAchieved / unmarkAchieved は $goal->enrollment をたどる。
        //      親が論理削除されていると belongsTo が null を返すので、自動的に false になる。
        //    - create は親インスタンスを画面から直接受け取るのでたどる必要がなく、
        //      論理削除済みでも user_id が読めてしまい true を返す。
        //
        //    これが無いと、解除済みの受講登録詳細に「目標を追加」フォームだけが表示され、
        //    送信すると 404 になる(実測で確認)。書き込みの拒否はルート層が引き続き担当し
        //    (decisions #132)、ここが担うのは画面の出し分け。
        if ($enrollment->trashed()) {
            return false;
        }

        return $this->isOwnerStudent($user, $enrollment);
    }

    /**
     * 編集できるか。enrollment-goal/_form.blade.php:93 と edit 画面の認可に使う。
     */
    public function update(User $user, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($user, $goal);
    }

    /**
     * 削除できるか。enrollment-goal/_form.blade.php:98。
     */
    public function delete(User $user, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($user, $goal);
    }

    /**
     * 達成マークを付けられるか。enrollment-goal/_form.blade.php:86。
     */
    public function markAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($user, $goal);
    }

    /**
     * 達成マークを外せるか。enrollment-goal/_form.blade.php:78。
     */
    public function unmarkAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($user, $goal);
    }

    /**
     * 目標の親をたどって「自分の受講登録の目標か」を判定する。操作系 4 つの共通部分。
     *
     * ⚠️ $goal->enrollment が null になる経路がある。Enrollment は SoftDeletes を使っていて、
     *    belongsTo は既定で論理削除済みの親を返さないため、受講解除した受講登録の目標は null を返す
     *    (実測で確認)。解除済みの受講登録詳細は withTrashed 付きで開けるので(enrollments.show のルート定義)、
     *    そこで @can が評価される。null を想定しないと TypeError で画面が 500 になる。
     *
     *    null を false に倒すことで、解除済みでは操作ボタンが出なくなる。
     *    ⚠️ 受講解除では配下の目標を物理削除するので(decisions #135)、通常この経路は通らない。
     *    ここは「目標だけが残った状態」に備える二重の守り。
     *    書き込みの拒否はルート層も担当する(decisions #132)。
     */
    private function isOwner(User $user, EnrollmentGoal $goal): bool
    {
        $enrollment = $goal->enrollment;

        return $enrollment !== null
            && $this->isOwnerStudent($user, $enrollment);
    }

    /**
     * 「その受講登録の持ち主である受講生か」だけを見る最小の判定。
     *
     * ⚠️ create() をそのまま使い回さないのは、create にだけ効く条件を後から足したとき
     *    (例: 1 受講登録あたりの上限件数)、編集・削除・達成マークまで巻き添えで拒否されるため。
     *    追加できない状態でも既存の目標は直せる、が正しい振る舞いになる。
     */
    private function isOwnerStudent(User $user, Enrollment $enrollment): bool
    {
        return $user->role === UserRole::Student
            && $enrollment->user_id === $user->id;
    }
}
