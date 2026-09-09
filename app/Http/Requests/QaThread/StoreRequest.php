<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Enums\CertificationStatus;
use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 質問スレッドの新規投稿リクエスト。投稿できるのは受講生のみ(`QaThreadPolicy::create()`)。
 *
 * 上限値は支給 Blade の属性に合わせている(`create.blade.php:43,52`。title の maxlength="200"、
 * body の :maxlength="5000")。資格は公開中のものだけを選べる(同 `:30` のプレースホルダ
 * 「公開中の資格から選択」)。受講登録は前提にしない(同 `:32` hint「受講していない資格でも質問できます。」)。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', QaThread::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'certification_id' => [
                'required',
                'ulid',
                // 公開停止中の資格を選ばせない。exists だけでは status を確認できない
                Rule::exists('certifications', 'id')->where('status', CertificationStatus::Published->value),
            ],
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
            'certification_id' => '資格',
            'title' => 'タイトル',
            'body' => '本文',
        ];
    }
}
