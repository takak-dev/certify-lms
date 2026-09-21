<?php

declare(strict_types=1);

namespace App\Http\Requests\GoogleCalendar;

use App\Http\Controllers\Settings\GoogleCalendarController;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Google の同意画面へ送り出すリクエスト(S-A-01)。
 *
 * 認可は `role:coach` middleware が担う。対象は常に認証ユーザー本人で、
 * ルートパラメータを持たないため authorize() で見るものが無い
 * (tests/Feature/Architecture/SelfServiceRouteArchitectureTest がこの前提を機械で固定している)。
 *
 * 手本: Meeting/AvailabilityRequest —— GET のクエリパラメータでも FormRequest を使う先例。
 *
 * @see GoogleCalendarController::redirect()
 */
class RedirectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * ⚠️ 配列を渡されても落ちないように、検証の前にスカラー以外を捨てる。
     *    `?redirect_path[]=a` のような入力は `(string)` キャストで TypeError になるため、
     *    ここで null に均しておく。
     *    値の妥当性(自サイト内のパスか)は rules() ではなく safeRedirectPath() で判断する ——
     *    バリデーション失敗は直前ページへ戻す挙動になり、ここでは戻り先が定まらないため。
     *    ⚠️ max を超える入力では失敗しうる(その場合は設定画面へ戻るだけで実害はない)。
     */
    protected function prepareForValidation(): void
    {
        if (! is_string($this->input('redirect_path'))) {
            $this->merge(['redirect_path' => null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'redirect_path' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'redirect_path' => '連携後の戻り先',
        ];
    }

    /**
     * 支給 Blade が渡してくる redirect_path を、自サイト内の相対パスに限って受け入れる。
     *
     * ⚠️ クエリの値をそのまま redirect() に渡すと **オープンリダイレクト**になる。
     *    ?redirect_path=https://evil.example/ を仕込んだリンクを踏ませると、
     *    「LMS を経由して外部サイトへ送られる」ため利用者が本物だと誤認しやすい。
     *
     * 許すのは「/ で始まり // で始まらない」形だけ。
     * //evil.example はスキーマを省略した絶対 URL(プロトコル相対 URL)として解釈され、
     * /\evil.example もブラウザによっては同じ扱いになるため、どちらも弾く。
     */
    public function safeRedirectPath(): string
    {
        $fallback = route('settings.availability.index');
        $path = $this->validated()['redirect_path'] ?? null;

        if (! is_string($path) || $path === '') {
            return $fallback;
        }

        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }

        if (str_contains($path, '\\')) {
            return $fallback;
        }

        // ⚠️ 連携動線そのものを戻り先にできないようにする。
        //    /settings/google-calendar/connect を戻り先に指定されると、.env 未設定の環境では
        //    「設定されていません」で同じ URL へ戻り続けてリダイレクトループになる。
        //    callback を指定された場合も、検証失敗 → 同じ URL → 検証失敗… で同じ結果。
        //
        // ⚠️ ここ **だけ** 復号してから判定する。Laravel のルート照合は rawurldecode した
        //    パスで行われるため(vendor/laravel/framework/src/Illuminate/Routing/Matching/UriValidator.php)、
        //    生の値で前方一致を見ると `/%73ettings/google-calendar/connect` がすり抜ける。
        //    逆に上の `//` と `\` の判定は **生のまま** にすること —— ブラウザは Location の
        //    %2F / %5C を復号しないので、復号して判定すると同一オリジンに留まるパスまで弾いてしまう。
        if (str_starts_with(rawurldecode($path), '/settings/google-calendar')) {
            return $fallback;
        }

        return $path;
    }
}
