<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 本人のプロフィール更新(氏名 / 自己紹介、コーチのみ固定面談 URL)のリクエスト。
 *
 * 認可は常に true。ルートがパラメータを持たず更新対象が常にログイン中の本人になるため、
 * 「他人を更新しようとしている」状態が作れない(routes/web.php の設定グループのコメント参照)。
 *
 * ⚠️ email は rules に入れない。画面では readonly だが、それは表示側の制御でしかない。
 *    rules に無ければ validated() にも乗らないので、フォームを改ざんして送られても更新されない。
 *    原典スコープ外「メールアドレスの変更動線 — 管理者経由のみ」を守るのはこの1点。
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:1000'],
        ];

        // 固定面談 URL はコーチだけが持つ項目。受講生 / 管理者が送っても rules に無いので
        // validated() に乗らず、無視される(原典「入力欄が現れず、操作もできない」の後半)。
        // オンボーディングでは必須だが、設定画面では任意(未設定のコーチ向けの警告カードが
        // meeting/show.blade.php:72-76 に用意されており、空を許す前提で画面が作られている)。
        if ($this->user()?->role === UserRole::Coach) {
            // url:http,https — 素の url ルールは file: や data: など 200 種類以上のスキームを許す
            // (Str::isUrl() の既定)。この値は予約時に StoreAction::__invoke() で meeting_url_snapshot へ
            // 写され、受講生の画面の <a href> に出る(meeting/show.blade.php:61,65)
            $rules['meeting_url'] = ['nullable', 'string', 'url:http,https', 'max:500'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => '氏名',
            'bio' => '自己紹介',
            'meeting_url' => '固定面談 URL',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'meeting_url.url' => '固定面談 URL を正しい形式で入力してください。',
        ];
    }
}
