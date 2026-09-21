<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\GoogleCalendar\CallbackRequest;
use App\Http\Requests\GoogleCalendar\RedirectRequest;
use App\Services\GoogleCalendarService;
use App\Services\GoogleOAuthStateService;
use App\UseCases\GoogleCalendar\ConnectAction;
use App\UseCases\GoogleCalendar\DisconnectAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * コーチが自分の Google アカウントを LMS と任意連携する OAuth 動線(S-A-01)。
 *
 * `redirect` が同意画面へ送り出し、`callback` が戻りを受けてトークンを保存し、
 * `destroy` が連携を解除する。入口は面談設定タブの連携カード
 * (settings/_partials/tab-meeting.blade.php:80-144)。
 * `role:coach` middleware で他ロールは 403。対象は常に認証ユーザー本人なので Policy は使わない。
 *
 * ⚠️ `callback` の失敗時だけ、共通の失敗処理に載せず自分で戻り先を指定している。
 *    Handler が HttpException を「直前ページへ戻す + flash error」に変換する仕組み
 *    (app/Exceptions/Handler.php:62-71)は `redirect()->back()` を使うが、
 *    OAuth の callback における「直前のページ」は連携開始 URL そのもの。
 *    共通機構に任せるとエラーのたびに連携をやり直す無限ループになる。
 *    state の発行・検証は GoogleOAuthStateService、トークン交換と保存は ConnectAction が持つ。
 */
class GoogleCalendarController extends Controller
{
    public function redirect(
        RedirectRequest $request,
        GoogleCalendarService $google,
        GoogleOAuthStateService $stateService,
    ): RedirectResponse {
        $backTo = $request->safeRedirectPath();

        // .env 未設定の環境でもルートは登録されている = 連携カードは出る。
        // 500 にせず理由を伝えて戻す(要件シート「利用には各自でのキー取得・.env 設定が必要」)。
        if (! $google->isConfigured()) {
            return redirect($backTo)
                ->with('error', 'Google カレンダー連携が設定されていません。管理者にお問い合わせください。');
        }

        $state = $stateService->issue($request->session(), $request->user(), $backTo);

        // away() は外部ドメインへのリダイレクト。route() や url() と違い自サイト前提の加工をしない。
        return redirect()->away($google->createAuthUrl($state));
    }

    public function callback(
        CallbackRequest $request,
        ConnectAction $action,
        GoogleOAuthStateService $stateService,
    ): RedirectResponse {
        $session = $request->session();
        $backTo = $stateService->redirectPath($session);

        // ⚠️ 検証に失敗した戻りでは **何も捨てない**。捨てると、第三者に不正な callback を
        //    踏ませるだけで保留中の正規フローを壊せる(GoogleOAuthStateService の docblock)。
        if (! $stateService->verify($session, $request->stateValue(), $request->user())) {
            return redirect($backTo)
                ->with('error', '連携リクエストを検証できませんでした。お手数ですが最初からやり直してください。');
        }

        // ここから先は本物の戻り。state は 1 回限りなので、結果によらずここで捨てる。
        $stateService->forget($session);

        // 同意画面で「キャンセル」を押すと error=access_denied で戻ってくる。失敗ではなく利用者の意思。
        if ($request->errorCode() !== null) {
            return redirect($backTo)->with('error', 'Google カレンダーとの連携を中止しました。');
        }

        if ($request->authorizationCode() === '') {
            return redirect($backTo)
                ->with('error', 'Google から認可情報を受け取れませんでした。再度お試しください。');
        }

        return ($action)($request->user(), $request->authorizationCode())
            ? redirect($backTo)->with('success', 'Google カレンダーと連携しました。')
            : redirect($backTo)->with('error', 'Google カレンダーとの連携に失敗しました。時間をおいて再度お試しください。');
    }

    public function destroy(Request $request, DisconnectAction $action): RedirectResponse
    {
        ($action)($request->user());

        return redirect()
            ->route('settings.availability.index')
            ->with('success', 'Google カレンダーの連携を解除しました。');
    }
}
