<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnrollmentGoal\StoreRequest;
use App\Http\Requests\EnrollmentGoal\UpdateRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\UseCases\EnrollmentGoal\DestroyAction;
use App\UseCases\EnrollmentGoal\MarkAchievedAction;
use App\UseCases\EnrollmentGoal\StoreAction;
use App\UseCases\EnrollmentGoal\UnmarkAchievedAction;
use App\UseCases\EnrollmentGoal\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 個人学習目標(EnrollmentGoal)の操作を受け持つ Controller。操作できるのは受講生本人のみ。
 *
 * 目標は独立した一覧 / 詳細画面を持たない(原典)。一覧と追加フォームは受講登録詳細に埋め込まれており、
 * 専用ページは編集だけ。そのため成功時の戻り先は edit を除きすべて受講登録詳細になる
 * (支給 Blade のキャンセルボタンも同じ場所を指している: enrollment-goal/edit.blade.php:54)。
 *
 * 認可の置き場は2通りに分かれる(既存の QaThreadController と同じ分担)。
 * - FormRequest を受け取るメソッド(store / update) … Request の authorize() が Policy を呼ぶ
 * - 受け取らないメソッド(edit / destroy / markAchieved / unmarkAchieved) … $this->authorize() を直に呼ぶ
 *
 * 手本: app/Http/Controllers/QaThreadController.php
 */
class EnrollmentGoalController extends Controller
{
    /**
     * 目標を追加する。親の受講登録は URL から決まる。
     */
    public function store(StoreRequest $request, Enrollment $enrollment, StoreAction $action): RedirectResponse
    {
        // 認可は StoreRequest::authorize() が済ませている(create Policy に親を渡す形)
        $action($enrollment, $request->validated());

        return redirect()
            ->route('enrollments.show', $enrollment)
            ->with('success', '目標を追加しました。');
    }

    /**
     * 編集ページを表示する。目標で唯一の専用画面。
     */
    public function edit(EnrollmentGoal $goal): View
    {
        $this->authorize('update', $goal);

        return view('enrollment-goal.edit', [
            'goal' => $goal,
        ]);
    }

    public function update(UpdateRequest $request, EnrollmentGoal $goal, UpdateAction $action): RedirectResponse
    {
        // 認可は UpdateRequest::authorize() が済ませている
        $action($goal, $request->validated());

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を更新しました。');
    }

    public function destroy(EnrollmentGoal $goal, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $goal);

        // 物理削除する前に戻り先の ID を控える。
        // 削除後も $goal の属性はメモリに残るので後から読んでも動く(実測で確認)が、
        // 消えたモデルから値を取る形は読み手に意図が伝わりにくい。
        // 既存の ChapterController.php:59 も同じく削除前に控えている
        $enrollmentId = $goal->enrollment_id;

        $action($goal);

        return redirect()
            ->route('enrollments.show', $enrollmentId)
            ->with('success', '目標を削除しました。');
    }

    public function markAchieved(EnrollmentGoal $goal, MarkAchievedAction $action): RedirectResponse
    {
        $this->authorize('markAchieved', $goal);

        $action($goal);

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を達成にしました。');
    }

    public function unmarkAchieved(EnrollmentGoal $goal, UnmarkAchievedAction $action): RedirectResponse
    {
        $this->authorize('unmarkAchieved', $goal);

        $action($goal);

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を未達成に戻しました。');
    }
}
