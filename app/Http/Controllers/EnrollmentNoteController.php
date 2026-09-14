<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnrollmentNote\StoreRequest;
use App\Http\Requests\EnrollmentNote\UpdateRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\UseCases\EnrollmentNote\DestroyAction;
use App\UseCases\EnrollmentNote\StoreAction;
use App\UseCases\EnrollmentNote\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 受講生メモ(EnrollmentNote)の操作を受け持つ Controller。操作できるのはコーチ(担当資格)と管理者のみ。
 *
 * メモは独立した一覧 / 詳細画面を持たない(原典)。一覧と追加フォームは受講登録詳細に埋め込まれており、
 * 専用ページは編集だけ。そのため成功時の戻り先は edit を除きすべて受講登録詳細になる
 * (支給 Blade のキャンセルボタンも同じ場所を指している: enrollment-note/edit.blade.php:37)。
 *
 * 認可の置き場は2通りに分かれる(既存の QaThreadController / EnrollmentGoalController と同じ分担)。
 * - FormRequest を受け取るメソッド(store / update) … Request の authorize() が Policy を呼ぶ
 * - 受け取らないメソッド(edit / destroy)           … $this->authorize() を直に呼ぶ
 *
 * フラッシュ文言の対象語は「コーチメモ」ではなく「メモ」。支給 Blade が文の中では
 * 「このメモを削除しますか？」(enrollment-note/_list.blade.php:55) /「メモを編集」(edit.blade.php:7) /
 * 「まだメモがありません。」(同 :34)と短い呼称を使っており、「コーチメモ」は見出しだけだから。
 * 姉妹機能も同じ形(カード見出し「個人目標」/ フラッシュ「目標を追加しました。」)。
 *
 * 手本: app/Http/Controllers/EnrollmentGoalController.php(姉妹機能 S-B-05。構造は同じで権限だけ真逆)
 */
class EnrollmentNoteController extends Controller
{
    /**
     * メモを追加する。親の受講登録は URL から決まる。
     */
    public function store(StoreRequest $request, Enrollment $enrollment, StoreAction $action): RedirectResponse
    {
        // 認可は StoreRequest::authorize() が済ませている(create Policy に親を渡す形)
        $action($request->user(), $enrollment, $request->validated());

        return redirect()
            ->route('enrollments.show', $enrollment)
            ->with('success', 'メモを追加しました。');
    }

    /**
     * 編集ページを表示する。メモで唯一の専用画面。
     */
    public function edit(EnrollmentNote $note): View
    {
        // 専用の ability は作らず update を使い回す。原典の HTTP 表は GET edit の認可を
        // 「作成者本人 / 管理者」と書いており、PATCH と同じ条件だから。
        // 編集ページに入れる人 = 保存できる人、をずらさないためでもある。
        // 手本: EnrollmentGoalController::edit()
        $this->authorize('update', $note);

        return view('enrollment-note.edit', [
            'note' => $note,
        ]);
    }

    /**
     * メモの本文を更新する。
     */
    public function update(UpdateRequest $request, EnrollmentNote $note, UpdateAction $action): RedirectResponse
    {
        // 認可は UpdateRequest::authorize() が済ませている
        $action($note, $request->validated());

        // ⚠️ route() には $note->enrollment ではなく enrollment_id を渡す。属性の直読みなので
        //    リレーションを引くクエリが増えず、スコープの影響も受けない。
        //    支給 Blade も同じ書き方をしている(enrollment-note/edit.blade.php:12,37)。
        //    ここに到達する時点で、Policy が「解除済みでない」ことを保証している(decisions #157)。
        return redirect()
            ->route('enrollments.show', $note->enrollment_id)
            ->with('success', 'メモを更新しました。');
    }

    /**
     * メモを削除する(物理削除。原典「削除すると履歴は残らない」)。
     */
    public function destroy(EnrollmentNote $note, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $note);

        // 消えた本人の画面には戻れないので、戻り先を先に控えておく
        $enrollmentId = $note->enrollment_id;

        $action($note);

        return redirect()
            ->route('enrollments.show', $enrollmentId)
            ->with('success', 'メモを削除しました。');
    }
}
