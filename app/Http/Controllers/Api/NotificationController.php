<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\IndexRequest;
use App\Http\Resources\NotificationResource;
use App\UseCases\Notification\IndexAction;
use App\UseCases\Notification\MarkAllAsReadAction;
use App\UseCases\Notification\MarkAsReadAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;

/**
 * 通知ポップオーバー用の JSON API(S-A-05)。
 *
 * web 版の NotificationController(app/Http/Controllers/NotificationController.php)と
 * 同じ Action・同じ Policy を呼び、違うのは「画面を返すか / JSON を返すか」だけ。
 * 処理の中身を 2 か所に持たないため、既読化や一覧の取り方を変えるときは Action を直せば両方に効く。
 *
 * ロールでは分けない(decisions #259)。管理者に対してはポップオーバー自体を出さないことで
 * 「押しても何も起きない」を実現している(decisions #214)。
 */
class NotificationController extends Controller
{
    /**
     * 自分宛の通知(新着順・最新 20 件)と未読の総数を返す。
     *
     * `?tab=unread` なら未読だけを返す(ポップオーバーの未読タブ)。手元の 20 件を JS で絞る方式にしないのは、
     * 未読が 21 件目より古い位置にもあると、未読タブの件数(unread_count)と並ぶ行数が食い違うため(decisions #260)。
     *
     * 返す形: { "data": [ {id, title, message, created_at_human, is_unread}, ... ], "unread_count": 12 }
     */
    public function index(IndexRequest $request, IndexAction $action): AnonymousResourceCollection
    {
        $user = $request->user();

        // タブの値の検査は web 版と同じ FormRequest に任せる(all / unread 以外は 422)。未指定は全件
        $tab = $request->validated()['tab'] ?? 'all';

        // web 版の一覧と同じ Action で、1 ページ目(20 件)だけを使う。
        // ポップオーバーはページ送りを持たない(原典スコープ外「最新分のみ表示」)ので、
        // 続きは「すべての通知を見る」からフルページで見てもらう
        $notifications = $action($user, $tab);

        // collection() に paginator をそのまま渡すと、ページ送り用の links / meta まで JSON に載る。
        // ポップオーバーでは使わないので、中身の通知だけ(getCollection())を渡す
        return NotificationResource::collection($notifications->getCollection())
            // additional() は data の外側に項目を足す Laravel の標準の書き方。
            // 未読の総数は画面の行を数えても出せない(最新 20 件しか返さないため)ので、サーバーで数えて渡す
            ->additional([
                'unread_count' => $user->unreadNotifications()->count(),
            ]);
    }

    /**
     * 通知を 1 件既読にし、遷移先の URL を返す。
     *
     * web 版はここでリダイレクトするが、fetch はリダイレクトを画面に反映せず裏で追いかけるだけなので、
     * URL を JSON で渡して JS に `location.href` で移動してもらう。
     */
    public function markAsRead(DatabaseNotification $notification, MarkAsReadAction $action): JsonResponse
    {
        // 他人の通知なら 403(web 版と同じ判定。NotificationPolicy::markAsRead)
        $this->authorize('markAsRead', $notification);

        return response()->json([
            'url' => $action($notification),
        ]);
    }

    /**
     * 自分宛の未読をまとめて既読にし、処理後の未読件数を返す。
     *
     * 「0 件」と決め打ちしないのは、押してから処理が終わるまでに新しい通知が届くことがあるため。
     * 正しい件数はサーバーにしか分からない。
     */
    public function markAllAsRead(Request $request, MarkAllAsReadAction $action): JsonResponse
    {
        $user = $request->user();
        $action($user);

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }
}
