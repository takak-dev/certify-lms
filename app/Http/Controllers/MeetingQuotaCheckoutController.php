<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MeetingQuota\CheckoutCreateRequest;
use App\Http\Requests\MeetingQuota\CheckoutSuccessRequest;
use App\Models\MeetingPack;
use App\UseCases\MeetingQuota\CheckoutCreateAction;
use App\UseCases\MeetingQuota\CheckoutSelectAction;
use App\UseCases\MeetingQuota\CheckoutSuccessAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 受講生が追加面談パックを購入する導線の Controller(S-A-03)。
 *
 * ルートは ['auth', 'role:student', 'active-learning'] のグループ内に置いてあるため、
 * コーチ / 管理者 / 学習中でない受講生はここへ到達しない(原典 インターフェース「受講生(学習中)のみ」)。
 *
 * 決済そのものは Stripe の外部画面に委譲し、結果は StripeWebhookController で受け取る。
 * この Controller は「どのパックを選んだか」を受け取って決済画面へ送り出すところまでを担う。
 *
 * 手本: app/Http/Controllers/MeetingPackController.php(Action をメソッド引数で受け取る形)
 */
class MeetingQuotaCheckoutController extends Controller
{
    /**
     * 購入画面。公開中の面談パックを並べる。
     */
    public function select(CheckoutSelectAction $action): View
    {
        return view('meeting-quota.checkout-select', [
            // 支給 Blade が $plans という名前で受け取る(checkout-select.blade.php:27,37)
            'plans' => $action(),
        ]);
    }

    /**
     * 購入を開始し、Stripe の決済画面へ送り出す。
     *
     * ⚠️ redirect()->away() を使う。away() は渡した文字列をそのまま Location にする
     *    (Redirector::away())。to() は UrlGenerator を通すため自サイトの URL として
     *    補正されてしまい、外部ドメインの送り先には向かない。
     *
     * ⚠️ 購入できない場合(公開中でない / Stripe に繋がらない)は Action が 409 を投げ、
     *    Handler.php が直前の画面へ戻して error フラッシュを出す。ここで try-catch は書かない
     *    (_共通ルール.md §2)。
     */
    public function create(CheckoutCreateRequest $request, CheckoutCreateAction $action): RedirectResponse
    {
        $validated = $request->validated();

        // 存在確認は FormRequest の exists:meeting_packs,id が済ませているので、ここに来る ID は
        // 必ず実在する。それでも findOrFail にしているのは、検証をすり抜けた場合に
        // 「null に対するプロパティ参照」で分かりにくく壊れるより 404 で止まるほうが安全なため。
        $pack = MeetingPack::query()->findOrFail($validated['meeting_pack_id']);

        return redirect()->away(
            $action($request->user(), $pack),
        );
    }

    /**
     * 決済完了画面。Stripe から `?session_id=cs_...` 付きで戻ってくる。
     *
     * ⚠️ この時点で残面談回数が増えているとは限らない。加算するのは Webhook で、
     *    ブラウザのリダイレクトとは別経路のため到着順が保証されない。支給 Blade も
     *    「反映までに数秒〜数十秒の遅延が生じることがあります」と案内している
     *    (meeting-quota/success.blade.php:19)。
     */
    public function success(CheckoutSuccessRequest $request, CheckoutSuccessAction $action): View
    {
        $validated = $request->validated();

        return view('meeting-quota.success', [
            // 支給 Blade が $payment という名前で受け取り、null なら購入サマリを出さない
            'payment' => $action($request->user(), $validated['session_id'] ?? null),
        ]);
    }
}
