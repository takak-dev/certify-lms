<?php

declare(strict_types=1);

namespace App\Http\Requests\QaReply;

use App\Models\QaReply;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 回答の編集リクエスト。編集できるのは投稿者本人のみ(`QaReplyPolicy::update()`)。
 * 管理者はモデレーション削除だけが可能で、内容の編集はできない(原典のスコープ外)。
 */
class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reply = $this->route('reply');

        return $reply instanceof QaReply
            && ($this->user()?->can('update', $reply) ?? false);
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
