<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Exceptions\AiChat\DailyMessageLimitExceededException;
use App\Exceptions\AiChat\GeminiNotConfiguredException;
use App\Exceptions\AiChat\GeminiRequestFailedException;
use App\Models\AiChatConversation;
use App\Models\Section;
use App\Models\User;

/**
 * 会話を起こし、最初の質問が添えられていればその場で送信まで済ませる Action(S-A-02)。
 *
 * POST /ai-chat/conversations の入口。既存の流儀に合わせて**入口の Action は HTTP 操作名**にし、
 * 下請けを FindOrCreateConversationAction / StoreMessageAction / GenerateTitleAction に分ける
 * (手本: app/UseCases/Invitation/StoreAction.php が下請けの IssueInvitationAction を呼ぶ形)。
 *
 * 支給モーダル resources/views/ai-chat/_partials/new-conversation-modal.blade.php:12 の
 * 「最初の質問 (任意、後から送信可)」を、保存だけして放置しない(decisions #239)。
 *
 * ⭐ **失敗の握りつぶしをここに置く理由。** 会話は作れているので、送信が失敗しても
 *    受講生はその会話へ進めたほうがよい(エラーの応答が画面に出て、同じ内容を送り直せる)。
 *    この「作れたが送れなかった」という業務判断は Controller の仕事ではない ——
 *    docs/tickets/_共通ルール.md:51「個別の Controller で try-catch を書く必要はない」。
 *
 * 戻り値の `error` は受講生に見せる案内文。Controller はこれをフラッシュに載せるだけでよい。
 */
final class StoreAction
{
    public function __construct(
        private readonly FindOrCreateConversationAction $findOrCreateConversation,
        private readonly StoreMessageAction $storeMessage,
        private readonly GenerateTitleAction $generateTitle,
    ) {}

    /**
     * @return array{conversation: AiChatConversation, created: bool, error: string|null}
     *                                                                                    `created` が false なら既存会話の再開(decisions #201)
     */
    public function __invoke(User $user, ?Section $section = null, ?string $firstMessage = null): array
    {
        $result = ($this->findOrCreateConversation)($user, $section, $firstMessage);
        $conversation = $result['conversation'];
        $error = null;

        if (filled($firstMessage)) {
            try {
                ($this->storeMessage)($conversation, (string) $firstMessage);
                ($this->generateTitle)($conversation);
            } catch (DailyMessageLimitExceededException|GeminiNotConfiguredException $e) {
                // 上限超過と環境の不備。どちらも行は作られていないので、案内だけを返す。
                $error = $e->getMessage();
            } catch (GeminiRequestFailedException) {
                // AI の失敗。質問とエラーの応答は StoreMessageAction が保存済みで、
                // 会話を開けばその状態がそのまま描かれる(原典「失敗しても質問は残り、送り直せる」)。
                // 画面側に重ねて案内を出す必要はない。
            }
        }

        return [
            'conversation' => $conversation,
            'created' => $result['created'],
            'error' => $error,
        ];
    }
}
