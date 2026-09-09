<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\QaReply\StoreRequest;
use App\Http\Requests\QaReply\UpdateRequest;
use App\Models\QaReply;
use App\Models\QaThread;
use App\UseCases\QaReply\DestroyAction;
use App\UseCases\QaReply\StoreAction;
use App\UseCases\QaReply\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 質問掲示板の回答 Controller。
 *
 * 回答は独立した画面を持たず、常にスレッド詳細の一部として扱う。
 * そのため投稿後は必ず親スレッドの詳細へ戻す。
 */
class QaReplyController extends Controller
{
    public function store(StoreRequest $request, QaThread $thread, StoreAction $action): RedirectResponse
    {
        $action($request->user(), $thread, $request->validated());

        return redirect()
            ->route($this->showRouteName(), $thread)
            ->with('success', '回答を投稿しました。');
    }

    public function edit(QaThread $thread, QaReply $reply): View
    {
        $this->ensureReplyBelongsToThread($thread, $reply);
        $this->authorize('update', $reply);

        return view('qa-thread.reply-edit', [
            'thread' => $thread,
            'reply' => $reply,
        ]);
    }

    public function update(UpdateRequest $request, QaThread $thread, QaReply $reply, UpdateAction $action): RedirectResponse
    {
        $this->ensureReplyBelongsToThread($thread, $reply);

        $action($reply, $request->validated());

        return redirect()
            ->route($this->showRouteName(), $thread)
            ->with('success', '回答を更新しました。');
    }

    public function destroy(QaThread $thread, QaReply $reply, DestroyAction $action): RedirectResponse
    {
        $this->ensureReplyBelongsToThread($thread, $reply);
        $this->authorize('delete', $reply);

        $action($reply);

        return redirect()
            ->route($this->showRouteName(), $thread)
            ->with('success', '回答を削除しました。');
    }

    /**
     * 管理者のモデレーション削除は admin.qa-board.show へ戻す(公開画面のルートは admin を通さない)。
     */
    private function showRouteName(): string
    {
        return request()->routeIs('admin.*') ? 'admin.qa-board.show' : 'qa-board.show';
    }

    /**
     * URL の {thread} と {reply} が親子関係にあることを確認する。
     *
     * ルートモデルバインディングは既定で親子の整合を検証しないため、別スレッドの回答 ID を
     * 指した URL でも到達できてしまう。噛み合わない組み合わせは存在しない資源として 404 にする。
     * 手本: `MockExamCatalogController.php:43`(受講登録と模試の資格が一致しない場合の扱い)。
     */
    private function ensureReplyBelongsToThread(QaThread $thread, QaReply $reply): void
    {
        if ($reply->qa_thread_id !== $thread->id) {
            throw new NotFoundHttpException;
        }
    }
}
