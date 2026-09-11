<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Notification\IndexRequest;
use App\UseCases\Notification\IndexAction;
use App\UseCases\Notification\MarkAllAsReadAction;
use App\UseCases\Notification\MarkAsReadAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

/**
 * 自分宛の通知の一覧・既読化を担う Controller。
 *
 * ロールでは分けない。受講生・コーチ・管理者のいずれも自分宛の通知一覧を開ける
 * (修了者も開ける。`app/Http/Middleware/EnsureActiveLearning.php:16` が通知一覧を名指しで除外している)。
 */
class NotificationController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $validated = $request->validated();
        $user = $request->user();

        // タブ未指定は「全件」。x-tabs の既定(配列の先頭キー)と揃える
        $tab = $validated['tab'] ?? 'all';

        return view('notifications.index', [
            'notifications' => $action($user, $tab),
            // 見出しの「現在 N 件の未読通知があります」と一括既読ボタンの出し分けに使う
            // (resources/views/notifications/index.blade.php:17,23)
            'unreadCount' => $user->unreadNotifications()->count(),
            'tab' => $tab,
        ]);
    }

    /**
     * 通知 1 件の全文を表示する(S-B-08 で追加)。
     *
     * 遷移先の業務画面を持たない通知——運営お知らせ——の本文をここで読む。
     * Action を挟まないのは、ルートモデルバインディングで取れた 1 件を
     * そのまま渡すだけで、組み立てる処理が無いため。
     */
    public function show(DatabaseNotification $notification): View
    {
        $this->authorize('view', $notification);

        return view('notifications.show', [
            'notification' => $notification,
        ]);
    }

    /**
     * 通知を 1 件既読にし、その通知が指す業務画面へ送る。
     */
    public function markAsRead(DatabaseNotification $notification, MarkAsReadAction $action): RedirectResponse
    {
        $this->authorize('markAsRead', $notification);

        // 遷移先は通知ごとに違うため route() で書けない。
        // decisions #31「redirect()->back() を使わない」の趣旨(行き先を確定させる)は、
        // Action が返す URL を検証したうえで使うことで満たしている
        return redirect($action($notification));
    }

    /**
     * 自分宛の未読通知をまとめて既読にする。
     */
    public function markAllAsRead(Request $request, MarkAllAsReadAction $action): RedirectResponse
    {
        $action($request->user());

        return redirect()
            ->route('notifications.index')
            ->with('success', '通知をすべて既読にしました。');
    }
}
