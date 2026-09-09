<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 質問スレッドの編集リクエスト。編集できるのは投稿者本人のみ(`QaThreadPolicy::update()`)。
 *
 * **`certification_id` はあえて rules() に含めない。** 原典が「資格は変更できない」と定めており
 * (decisions #65)、支給の編集フォームにも入力欄が無い(`edit.blade.php` は title / body のみ)。
 * rules() に無いキーは `validated()` から除外されるため、手で送られても無視される。
 */
class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $thread = $this->route('thread');

        return $thread instanceof QaThread
            && ($this->user()?->can('update', $thread) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'タイトル',
            'body' => '本文',
        ];
    }
}
