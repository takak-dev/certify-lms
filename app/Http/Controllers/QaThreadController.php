<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Http\Requests\QaThread\IndexRequest;
use App\Http\Requests\QaThread\StoreRequest;
use App\Http\Requests\QaThread\UpdateRequest;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\QaThread\DestroyAction;
use App\UseCases\QaThread\IndexAction;
use App\UseCases\QaThread\ResolveAction;
use App\UseCases\QaThread\ShowAction;
use App\UseCases\QaThread\StoreAction;
use App\UseCases\QaThread\UnresolveAction;
use App\UseCases\QaThread\UpdateAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 質問掲示板(受講生 / コーチ)の Controller。
 *
 * 管理者のモデレーション画面は同じ Blade を共用し、`request()->routeIs('admin.*')` で
 * 表示を切り替える(`index.blade.php:9`)。そのため View 名は公開・管理者で共通。
 */
class QaThreadController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $validated = $request->validated();
        $viewer = $request->user();

        $threads = $action(
            viewer: $viewer,
            status: isset($validated['status']) ? QaThreadStatus::from($validated['status']) : null,
            certificationId: $validated['certification_id'] ?? null,
            keyword: $validated['keyword'] ?? null,
        );

        return view('qa-thread.index', [
            'threads' => $threads,
            'filters' => [
                'status' => $validated['status'] ?? '',
                'certification_id' => $validated['certification_id'] ?? '',
                'keyword' => $validated['keyword'] ?? '',
            ],
            'certifications' => $this->filterableCertifications($viewer),
            'publishedStatus' => CertificationStatus::Published,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', QaThread::class);

        return view('qa-thread.create', [
            // 投稿先に選べるのは公開中の資格のみ(`create.blade.php:30` のプレースホルダ)
            'certifications' => Certification::query()->published()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $thread = $action($request->user(), $request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を投稿しました。');
    }

    public function show(QaThread $thread, ShowAction $action): View
    {
        $this->authorize('view', $thread);

        return view('qa-thread.show', [
            'thread' => $action($thread),
        ]);
    }

    public function edit(QaThread $thread): View
    {
        $this->authorize('update', $thread);

        return view('qa-thread.edit', [
            'thread' => $thread,
        ]);
    }

    public function update(UpdateRequest $request, QaThread $thread, UpdateAction $action): RedirectResponse
    {
        $action($thread, $request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を更新しました。');
    }

    public function destroy(QaThread $thread, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $thread);

        // 回答が付いていれば DestroyAction が 409 を投げ、Handler が直前ページへ戻す
        $action($thread, request()->user());

        return redirect()
            ->route($this->routeName('index'))
            ->with('success', '質問を削除しました。');
    }

    public function resolve(QaThread $thread, ResolveAction $action): RedirectResponse
    {
        $this->authorize('resolve', $thread);

        $action($thread);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を解決済にしました。');
    }

    public function unresolve(QaThread $thread, UnresolveAction $action): RedirectResponse
    {
        $this->authorize('unresolve', $thread);

        $action($thread);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を未解決に戻しました。');
    }

    /**
     * 公開画面と管理者モデレーション画面で同じ Controller / Blade を共用するため、
     * 現在のルートに合わせて遷移先のルート名を切り替える(`index.blade.php:9-11` と同じ判定)。
     */
    private function routeName(string $suffix): string
    {
        return request()->routeIs('admin.*')
            ? 'admin.qa-board.'.$suffix
            : 'qa-board.'.$suffix;
    }

    /**
     * 絞り込みチップに並べる資格。範囲の判定は `Certification::scopeSelectableOnQaBoard()` に集約している
     * (一覧の可視範囲 `QaThread::scopeForUser()` と二重に書かないため)。
     *
     * @return Collection<int, Certification>
     */
    private function filterableCertifications(User $viewer): Collection
    {
        return Certification::query()
            ->selectableOnQaBoard($viewer)
            ->orderBy('name')
            ->get();
    }
}
