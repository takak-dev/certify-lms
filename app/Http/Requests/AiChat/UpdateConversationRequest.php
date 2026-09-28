<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 会話タイトルの変更時の入力検証(S-A-02)。
 *
 * 支給 Blade resources/views/ai-chat/show.blade.php:146-153 のモーダルから送られる。
 * required と maxlength=100 はその入力欄に合わせてある(:151 / :152)。
 */
class UpdateConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof AiChatConversation
            && $this->user()?->can('update', $conversation) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * 項目名の日本語表記。
     *
     * ⚠️ これが無いと「title は必須です。」と英語のまま受講生に出る
     *    (lang/ja/validation.php:124-127 が明記。同ファイルの共通 attributes に title は無い)。
     *    支給 Blade resources/views/ai-chat/show.blade.php:141 はエラー時にモーダルを自動で開き、
     *    :150 が $errors->first('title') を描くので、必ず目に入る。
     *    文言は支給 Blade の label="タイトル"(:148)に合わせる。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'タイトル',
        ];
    }
}
