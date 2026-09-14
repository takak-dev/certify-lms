<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentNote;

use App\Models\EnrollmentNote;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 受講生メモの更新リクエスト。専用の編集ページ(enrollment-note/edit.blade.php)から届く。
 *
 * 認可は `EnrollmentNotePolicy::update()` に委ねる。こちらはメモの行が既にあるので、
 * 判定対象はメモそのもの。
 *
 * ⚠️ このルートは URL に受講登録を含まない(/enrollment-notes/{note})。そのため
 *    「解除済みの受講登録には書けない」(decisions #137 / #158)をルート層では弾けず、
 *    Policy の update() が $note->enrollment を引いて trashed() を見て止める(decisions #157)。
 *
 * ルールは Store と同じ内容を持たせる。既存の Section/UpdateRequest も共通化せず
 * 両方に書いているため、それに揃えた。
 */
class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // route('note') はルート定義の {note} と対応する
        $note = $this->route('note');

        return $note instanceof EnrollmentNote
            && ($this->user()?->can('update', $note) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * エラーメッセージに出す項目名。画面のラベルと同じ言葉にする
     * (enrollment-note/edit.blade.php:25 の label="メモ本文")。追加フォームとはラベルが違う。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => 'メモ本文',
        ];
    }
}
