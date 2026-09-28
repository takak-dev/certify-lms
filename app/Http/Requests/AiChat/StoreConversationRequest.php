<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * AI 相談の会話を作る(再開する)ときの入力検証。
 *
 * 送ってくるのは 2 経路で、フィールドが違う。
 *   - フローティングウィジェット … source='widget' + section_id(教材を開いているときだけ)
 *     (resources/js/ai-chat/floating-widget.js:253-257)
 *   - フル画面のモーダル         … source='full-screen' + message(任意・最初の質問)
 *     (resources/views/ai-chat/_partials/new-conversation-modal.blade.php:6,11)
 *
 * ⚠️ source は**保存しない**。原典にも支給画面にも「どこから始めたか」を使う場所が無く、
 *    列を作ると使われない情報を持ち続けることになる。検証だけして捨てる。
 */
class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AiChatConversation::class) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // 支給コードが送る 2 値だけを許す。未知の値は弾いて、経路の取り違えに早く気づけるようにする。
            'source' => ['required', 'string', Rule::in(['widget', 'full-screen'])],

            // 教材コンテキスト。存在しない Section を指されたら弾く。
            'section_id' => ['nullable', 'string', Rule::exists(Section::class, 'id')],

            // 最初の質問(任意)。上限は支給画面の入力欄に合わせる
            // (new-conversation-modal.blade.php:14 の :maxlength="2000"、
            //  フル画面の入力欄 _partials/input-form.blade.php:19 も同じ 2000)。
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * 項目名の日本語表記(lang/ja/validation.php:124-127)。
     *
     * 支給モーダルはエラーを描画していないため実害は小さいが、大半の FormRequest が attributes() を持つ既存の流儀に揃える
     * (本チケット追加後で 83 本中 66 本。2026-09-28 実測)。文言は支給 Blade の label に合わせる
     * (resources/views/ai-chat/_partials/new-conversation-modal.blade.php:12)。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'message' => '最初の質問',
            'section_id' => '教材',
        ];
    }
}
