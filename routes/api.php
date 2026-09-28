<?php

declare(strict_types=1);

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| JSON API のルート定義。
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// ============================================================
// 通知 JSON API(S-A-05)— トップバーの通知ポップオーバーが叩く
// ============================================================
// このファイルのルートには RouteServiceProvider が `api` を前置するので、prefix は `v1` だけ書く。
// auth:sanctum は「画面からの fetch ならセッション Cookie で認証する」ガード
// (api グループの EnsureFrontendRequestsAreStateful がセッションを有効にする。app/Http/Kernel.php:76)。
// ロールでは絞らない。管理者も web の通知一覧で同じ操作ができるため(decisions #259)。
// 他人の通知は web 版と同じ NotificationPolicy が 403 で弾く。
// web 側の同名ルート(routes/web.php:776-787)と並行して動く。置き換えではない(原典スコープ外)。
Route::middleware('auth:sanctum')
    ->prefix('v1')
    ->name('api.v1.')
    ->group(function () {
        Route::get('notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
            ->name('notifications.markAsRead');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead'])
            ->name('notifications.markAllAsRead');
    });
