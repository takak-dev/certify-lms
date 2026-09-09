<?php

declare(strict_types=1);

namespace App\Http\Requests\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 回答の投稿リクエスト。投稿できるのは受講生とコーチのみ(管理者は不可)。
 *
 * 認可は `QaReplyPolicy::create()` に委ね、対象スレッドを第2引数で渡す
 * (支給 Blade も同じ形で判定している: `_reply-form.blade.php:3`)。
 * 上限 5000 文字は入力欄の `:maxlength="5000"`(同 `:10-18`)に合わせる。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $thread = $this->route('thread');

        return $thread instanceof QaThread
            && ($this->user()?->can('create', [QaReply::class, $thread]) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => '本文',
        ];
    }
}
