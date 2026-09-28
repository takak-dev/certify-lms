<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * AI 相談の会話を作る、または既存の会話を再開する Action(S-A-02)。
 *
 * ⭐ 教材から始めた相談は、同じ教材の会話を**作り直さず再開する**(decisions #201)。
 *    原典「教材から相談を始めた場合、同じ教材の会話が乱立しないようにする」。
 *    支給 JS resources/js/ai-chat/floating-widget.js:259-260 のコメントも
 *    「200 = 既存会話再開 / 201 = 新規作成」とサーバ側の挙動を前提に書かれている。
 *
 * ⚠️ 一意制約(user_id, section_id)は張らない。section_id が null の会話(全般相談 / 資格相談)は
 *    いくつでも作れてよく、MySQL の unique は NULL を重複と見なさないため**張っても効かない**。
 *    再開は検索で行い、索引 ai_chat_conversations(user_id, section_id) がそれを支える。
 */
final class FindOrCreateConversationAction
{
    /**
     * @return array{conversation: AiChatConversation, created: bool}
     *                                                                `created` は false なら既存の再開(呼び出し側が 200 / 201 を出し分ける)
     */
    public function __invoke(User $user, ?Section $section = null, ?string $firstMessage = null): array
    {
        if ($section !== null) {
            $existing = $user->aiChatConversations()
                ->where('section_id', $section->id)
                ->orderByDesc('last_message_at')
                ->first();

            if ($existing !== null) {
                return ['conversation' => $existing, 'created' => false];
            }
        }

        $conversation = $user->aiChatConversations()->create([
            'section_id' => $section?->id,
            'enrollment_id' => $this->resolveEnrollmentId($user, $section),
            'title' => $this->provisionalTitle($section, $firstMessage),
            // ⚠️ 1 通も無い時点でも入れておく。null のままだと支給 Blade
            //    resources/views/ai-chat/show.blade.php:16-21 の振り分けが else に落ちて
            //    作りたての会話が履歴の「過去 30 日」に並び、AiChatController::index の
            //    orderByDesc('last_message_at') でも最新扱いされない(MySQL は DESC で NULL が最後)。
            //    支給 Blade を変えずに直せるのはここだけ。
            'last_message_at' => now(),
        ]);

        return ['conversation' => $conversation, 'created' => true];
    }

    /**
     * 会話に紐づける受講登録(資格文脈の供給元)。
     *
     * 教材から始めた場合はその教材が属する資格の受講登録を、そうでなければ既定の受講登録を使う。
     * 既定の受講登録の扱いは支給 Blade と同じ基準に揃える —— 学習中 / 合格のときだけ資格名を出す
     * (resources/views/ai-chat/_partials/context-badges.blade.php:12-17)。
     */
    private function resolveEnrollmentId(User $user, ?Section $section): ?string
    {
        if ($section !== null) {
            $certificationId = $section->chapter?->part?->certification_id;

            if ($certificationId !== null) {
                $enrollment = $user->enrollments()
                    ->where('certification_id', $certificationId)
                    ->first();

                if ($enrollment !== null) {
                    return $enrollment->id;
                }
            }
        }

        $default = $user->defaultEnrollment;

        return $default !== null
            && in_array($default->status, [EnrollmentStatus::Learning, EnrollmentStatus::Passed], true)
            ? $default->id
            : null;
    }

    /**
     * 作成時の仮タイトル。title は NOT NULL なので必ず何かを入れる。
     *
     * AI による自動生成は**初回の応答が完了した直後の 1 回だけ**走る
     * (支給 JS resources/js/ai-chat/chat-client.js:72 / GenerateTitleAction)。
     * それまでの間、履歴サイドバーに並ぶのがこの文字列になるので、
     * 「何の相談か」が人間に分かる材料を優先して選ぶ。
     */
    private function provisionalTitle(?Section $section, ?string $firstMessage): string
    {
        if (filled($firstMessage)) {
            return Str::limit(trim($firstMessage), 60);
        }

        if ($section !== null) {
            return Str::limit($section->title, 60);
        }

        return '新しい相談';
    }
}
