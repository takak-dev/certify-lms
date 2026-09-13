<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanStatus;
use App\Http\Requests\Plan\IndexRequest;
use App\Http\Requests\Plan\StoreRequest;
use App\Http\Requests\Plan\UpdateRequest;
use App\Models\Plan;
use App\UseCases\Plan\ArchiveAction;
use App\UseCases\Plan\DestroyAction;
use App\UseCases\Plan\IndexAction;
use App\UseCases\Plan\PublishAction;
use App\UseCases\Plan\ShowAction;
use App\UseCases\Plan\StoreAction;
use App\UseCases\Plan\UnarchiveAction;
use App\UseCases\Plan\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * admin 用の受講プラン(受講期間 + 初期付与面談回数)マスタ管理 Controller。
 * CRUD と状態遷移(publish / archive / unarchive)を提供する。
 *
 * ルートは role:admin グループ内に置いてあるため、受講生 / コーチはここへ到達しない。
 * 個々の操作の可否は PlanPolicy が判定する。
 *
 * 手本: app/Http/Controllers/MeetingPackController.php
 */
class PlanController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        // validated() は rules() に書いた項目だけを返す。書いていない項目は混ざらない
        $validated = $request->validated();

        $plans = $action(
            keyword: $validated['keyword'] ?? null,
            // 画面から来るのは 'draft' のような文字列。Enum に変換してから Action へ渡す。
            // 「全ステータス」を選ぶと空文字で届くが、ConvertEmptyStringsToNull
            // (app/Http/Kernel.php:57)が null に変えるので isset() が false になる
            status: isset($validated['status']) ? PlanStatus::from($validated['status']) : null,
        );

        return view('plan.management.index', [
            'plans' => $plans,
            // Blade は検索欄の value と選択中の状態に使う。null だと表示が崩れるので空文字にする
            'keyword' => $validated['keyword'] ?? '',
            'status' => $validated['status'] ?? '',
        ]);
    }

    public function show(Plan $plan, ShowAction $action): View
    {
        // 'view' は PlanPolicy::view() を呼ぶ。文字列なのでエディタからは辿れない
        $this->authorize('view', $plan);

        return view('plan.management.show', [
            'plan' => $action($plan),
        ]);
    }

    public function create(): View
    {
        // 作成前なのでモデルのインスタンスが無い。クラス名を渡すのが Laravel の作法
        $this->authorize('create', Plan::class);

        return view('plan.management.create');
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        // 認可は StoreRequest::authorize() で済んでいる。Controller では改めて判定しない
        $plan = $action($request->user(), $request->validated());

        return redirect()
            ->route('admin.plans.show', $plan)
            ->with('success', 'プランを作成しました。');
    }

    public function edit(Plan $plan): View
    {
        // 編集フォームを開く権限は「更新してよいか」と同じ。update を使い回す
        $this->authorize('update', $plan);

        return view('plan.management.edit', [
            'plan' => $plan,
        ]);
    }

    public function update(Plan $plan, UpdateRequest $request, UpdateAction $action): RedirectResponse
    {
        $action($plan, $request->user(), $request->validated());

        return redirect()
            ->route('admin.plans.show', $plan)
            ->with('success', 'プランを更新しました。');
    }

    public function publish(Plan $plan, PublishAction $action): RedirectResponse
    {
        $this->authorize('publish', $plan);

        // 下書き以外から呼ばれると Action が 409 を投げ、Handler が同じ画面へ戻して理由を出す
        $action($plan, request()->user());

        return redirect()
            ->route('admin.plans.show', $plan)
            ->with('success', 'プランを公開しました。');
    }

    public function archive(Plan $plan, ArchiveAction $action): RedirectResponse
    {
        $this->authorize('archive', $plan);

        $action($plan, request()->user());

        return redirect()
            ->route('admin.plans.show', $plan)
            ->with('success', 'プランをアーカイブしました。');
    }

    public function unarchive(Plan $plan, UnarchiveAction $action): RedirectResponse
    {
        $this->authorize('unarchive', $plan);

        $action($plan, request()->user());

        return redirect()
            ->route('admin.plans.show', $plan)
            ->with('success', 'プランを下書きに戻しました。');
    }

    public function destroy(Plan $plan, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $plan);

        // 条件を満たさなければ Action が 409 を投げる。Handler が直前の画面へ戻して理由を出すので
        // ここで try-catch は書かない(手本: MeetingPackController::destroy)
        $action($plan);

        // 削除したプランの詳細画面には戻れないので一覧へ
        return redirect()
            ->route('admin.plans.index')
            ->with('success', 'プランを削除しました。');
    }
}
