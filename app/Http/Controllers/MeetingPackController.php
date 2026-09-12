<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MeetingPackStatus;
use App\Http\Requests\MeetingPack\IndexRequest;
use App\Http\Requests\MeetingPack\StoreRequest;
use App\Http\Requests\MeetingPack\UpdateRequest;
use App\Models\MeetingPack;
use App\UseCases\MeetingPack\ArchiveAction;
use App\UseCases\MeetingPack\DestroyAction;
use App\UseCases\MeetingPack\IndexAction;
use App\UseCases\MeetingPack\PublishAction;
use App\UseCases\MeetingPack\ShowAction;
use App\UseCases\MeetingPack\StoreAction;
use App\UseCases\MeetingPack\UnarchiveAction;
use App\UseCases\MeetingPack\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * admin 用の面談パック(追加購入用 SKU)マスタ管理 Controller。
 * CRUD と状態遷移(publish / archive / unarchive)を提供する。
 *
 * ルートは role:admin グループ内に置いてあるため、受講生 / コーチはここへ到達しない。
 * 個々の操作の可否は MeetingPackPolicy が判定する。
 *
 * 手本: app/Http/Controllers/CertificationController.php
 */
class MeetingPackController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        // validated() は rules() に書いた項目だけを返す。書いていない項目は混ざらない
        $validated = $request->validated();

        $plans = $action(
            keyword: $validated['keyword'] ?? null,
            // 画面から来るのは 'draft' のような文字列。Enum に変換してから Action へ渡す
            status: isset($validated['status']) ? MeetingPackStatus::from($validated['status']) : null,
        );

        return view('meeting-pack.management.index', [
            'plans' => $plans,
            // Blade は検索欄の value と選択中の状態に使う。null だと表示が崩れるので空文字にする
            'keyword' => $validated['keyword'] ?? '',
            'status' => $validated['status'] ?? '',
        ]);
    }

    public function show(MeetingPack $plan, ShowAction $action): View
    {
        // 'view' は MeetingPackPolicy::view() を呼ぶ。文字列なのでエディタからは辿れない
        $this->authorize('view', $plan);

        return view('meeting-pack.management.show', [
            'plan' => $action($plan),
        ]);
    }

    public function create(): View
    {
        // 作成前なのでモデルのインスタンスが無い。クラス名を渡すのが Laravel の作法
        $this->authorize('create', MeetingPack::class);

        return view('meeting-pack.management.create');
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        // 認可は StoreRequest::authorize() で済んでいる。Controller では改めて判定しない
        $plan = $action($request->user(), $request->validated());

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを作成しました。');
    }

    public function edit(MeetingPack $plan): View
    {
        // 編集フォームを開く権限は「更新してよいか」と同じ。update を使い回す
        $this->authorize('update', $plan);

        return view('meeting-pack.management.edit', [
            'plan' => $plan,
        ]);
    }

    public function update(MeetingPack $plan, UpdateRequest $request, UpdateAction $action): RedirectResponse
    {
        $action($plan, $request->user(), $request->validated());

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを更新しました。');
    }

    public function destroy(MeetingPack $plan, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $plan);

        // 公開中なら Action が例外を投げる。Handler が直前の画面へ戻して error を出すので
        // ここで try-catch は書かない(手本: CertificationController::destroy)
        $action($plan);

        // 削除した本人の詳細画面には戻れないので一覧へ
        return redirect()
            ->route('admin.meeting-packs.index')
            ->with('success', '面談パックを削除しました。');
    }

    public function publish(MeetingPack $plan, PublishAction $action): RedirectResponse
    {
        $this->authorize('publish', $plan);

        // 下書き以外から呼ばれると Action が 409 を投げ、Handler が同じ画面へ戻して理由を出す
        $action($plan, request()->user());

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを公開しました。');
    }

    public function archive(MeetingPack $plan, ArchiveAction $action): RedirectResponse
    {
        $this->authorize('archive', $plan);

        $action($plan, request()->user());

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックをアーカイブしました。');
    }

    public function unarchive(MeetingPack $plan, UnarchiveAction $action): RedirectResponse
    {
        $this->authorize('unarchive', $plan);

        $action($plan, request()->user());

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを下書きに戻しました。');
    }
}
