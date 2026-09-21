<?php

declare(strict_types=1);

namespace App\Http\Requests\GoogleCalendar;

use App\Http\Controllers\Settings\GoogleCalendarController;
use App\Services\GoogleOAuthStateService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Google の同意画面から戻ってくるリクエスト(S-A-01)。
 *
 * ここで見るのは **値の形だけ**（文字列か / 長すぎないか）。
 * 「その state が本人のものか」という意味の検証は Controller が担う —— 失敗したときに
 * セッションへ預けた戻り先まで含めて制御する必要があり、FormRequest の
 * 「失敗したら直前ページへ戻す」既定の振る舞いでは連携開始 URL へ戻ってループするため。
 *
 * @see GoogleCalendarController::callback()
 */
class CallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * ⚠️ 配列を渡されても落ちないように、検証の前にスカラー以外を捨てる。
     *    `?state[]=a` のような入力は `(string)` キャストで TypeError になる。
     *    形式が外れる入力は rules() で弾かれるが(max 超過など)、その場合も state は
     *    消費されないので安全側に倒れる。
     */
    protected function prepareForValidation(): void
    {
        foreach (['state', 'code', 'error'] as $key) {
            if (! is_string($this->input($key))) {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'state' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:2048'],
            'error' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'state' => '照合用トークン',
            'code' => '認可コード',
            'error' => 'エラー種別',
        ];
    }

    /**
     * バリデーション失敗時の戻り先を固定する。
     *
     * ⚠️ 既定の挙動は「直前のページへ戻す」だが、OAuth の callback における直前のページは
     *    連携開始 URL そのもの。そのまま戻すと再び Google へ送られてループする。
     *    形式が外れる入力（例: 長すぎる code）を踏まされたときに、ここで止める。
     */
    protected function failedValidation(Validator $validator): void
    {
        $backTo = app(GoogleOAuthStateService::class)->redirectPath($this->session());

        throw new HttpResponseException(
            redirect($backTo)->with('error', '連携リクエストを検証できませんでした。お手数ですが最初からやり直してください。'),
        );
    }

    /** Google が返した照合用トークン。無ければ空文字。 */
    public function stateValue(): string
    {
        return (string) ($this->validated()['state'] ?? '');
    }

    /** Google が返した認可コード。無ければ空文字。 */
    public function authorizationCode(): string
    {
        return (string) ($this->validated()['code'] ?? '');
    }

    /** 利用者が同意画面で中止した場合などに入る。無ければ null。 */
    public function errorCode(): ?string
    {
        $error = $this->validated()['error'] ?? null;

        return is_string($error) && $error !== '' ? $error : null;
    }
}
