<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Google 連携の OAuth で使う照合用トークン(state)の発行と検証(S-A-01)。
 *
 * 原典 共通の振る舞い「連携処理が正規の本人によるものか検証し、なりすましや不正な連携を拒否する」の実体。
 *
 * ⚠️ 検証は **値・発行者・有効期限の 3 つすべて** を見る。値の一致だけでは、攻撃者が自分の
 *    セッションで作った state を被害者のブラウザに持ち込む経路(セッション固定)を塞げない ——
 *    Laravel はログイン時にセッション ID を再生成するが **データは引き継ぐ** ため、
 *    ログイン前に仕込まれた値はログイン後も生き残る。
 *
 * ⚠️ **検証を通るまで捨てない。** 「読んで消す」を検証より前に置くと、第三者に
 *    `.../callback?error=x` を踏ませるだけで保留中の state を捨てさせられ、
 *    正規の戻りが必ず失敗する(連携の妨害)。同意画面を開いている最中に成立する。
 *    state は推測できない 40 文字の乱数なので、失敗時に残しても再利用の穴は開かない
 *    —— 一致した回にだけ消えるので「1 回限り」は保たれる。
 */
final class GoogleOAuthStateService
{
    /** 照合用トークンをセッションに預けるキー。 */
    public const SESSION_STATE = 'google_calendar.oauth_state';

    /** 連携完了後に戻る画面をセッションに預けるキー。 */
    public const SESSION_REDIRECT_PATH = 'google_calendar.redirect_path';

    /** state の有効期間(秒)。同意画面を開いたまま放置された古い state を無効にする。 */
    private const TTL_SECONDS = 600;

    /**
     * 照合用トークンを発行し、戻り先とあわせてセッションに預ける。
     *
     * @return string Google の認可 URL に載せる値
     */
    public function issue(Session $session, User $user, string $redirectPath): string
    {
        $state = Str::random(40);

        $session->put(self::SESSION_STATE, [
            'value' => $state,
            'user_id' => $user->id,
            'issued_at' => Carbon::now()->timestamp,
        ]);
        $session->put(self::SESSION_REDIRECT_PATH, $redirectPath);

        return $state;
    }

    /**
     * 連携完了後に戻る画面。預けていなければ面談設定画面に戻す。
     *
     * ⚠️ 読むだけで消さない。検証に失敗した戻りでも、保留中の正規フローを壊さないため。
     */
    public function redirectPath(Session $session): string
    {
        $path = $session->get(self::SESSION_REDIRECT_PATH);

        return is_string($path) && $path !== '' ? $path : route('settings.availability.index');
    }

    /**
     * 戻ってきた値が、預けた値・いまのログインユーザー・有効期限のすべてと合うか。
     */
    public function verify(Session $session, string $received, ?User $user): bool
    {
        $saved = $session->get(self::SESSION_STATE);

        if (! is_array($saved) || $user === null) {
            return false;
        }

        // ⚠️ hash_equals() を使う。== だと文字列の比較が「違いが見つかった時点で終わる」ため、
        //    一致した文字数で処理時間が変わり、当てられる余地が生まれる(タイミング攻撃)。
        //    hash_equals() は長さが同じなら必ず最後まで比較する。
        if (! is_string($saved['value'] ?? null) || ! hash_equals($saved['value'], $received)) {
            return false;
        }

        // ⭐ 発行した本人か。セッション固定で他人の state を持ち込まれても、ここで弾ける。
        if (($saved['user_id'] ?? null) !== $user->id) {
            return false;
        }

        return Carbon::now()->timestamp - (int) ($saved['issued_at'] ?? 0) < self::TTL_SECONDS;
    }

    /**
     * 預けた値を捨てる。**検証を通った後にだけ呼ぶこと**(クラス docblock の 2 つ目の ⚠️)。
     */
    public function forget(Session $session): void
    {
        $session->forget([self::SESSION_STATE, self::SESSION_REDIRECT_PATH]);
    }
}
