<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\DailyMessageLimitExceededException;
use App\Exceptions\AiChat\GeminiNotConfiguredException;
use App\Exceptions\AiChat\GeminiRequestFailedException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\AiChatContextService;
use App\Services\GeminiService;
use Illuminate\Support\Facades\DB;

/**
 * 受講生のメッセージを保存し、Gemini の応答を同期で受け取って保存する Action(S-A-02)。
 *
 * ⭐ **このチケットの核心は保存の順序。** 原典「AI 応答に失敗しても受講生の質問は残り、
 *    同じ内容を送り直して再質問できる」を満たすため、**Gemini の呼び出しを
 *    トランザクションに入れない**。入れると、AI の失敗で ROLLBACK が走り、
 *    受講生の質問の INSERT ごと消えてしまう(画面には失敗だけが出て、何を聞いたか分からなくなる)。
 *
 * 流れ:
 *   ① 入力を組み立てる(まだ何も保存しない)
 *   ② 受講生の発言を Completed で保存   ← ここから先どう転んでも質問は残る
 *   ③ AI の応答を Pending で保存        ← 支給 Blade のローディング表示と同じ状態
 *   ④ Gemini を呼ぶ                     ← ②③のトランザクションの**外**
 *   ⑤ 成功 → Completed + 本文 / 失敗 → Error + error_detail
 *
 * ⚠️ ①を②より先にやる理由。AiChatContextService は「履歴 + 今回の質問」を組み立てるので、
 *    先に保存すると今回の質問が履歴側にも入り、**同じ文が 2 回 Gemini に届く**。
 *    ①の時点ではまだ何も保存していないので、途中で落ちても失うものは無い。
 *
 * ⚠️ **トランザクションは②③だけを包み、④は絶対に入れない。** 入れると、AI の失敗で ROLLBACK が
 *    走って受講生の質問の INSERT ごと消える。②③を包むのは日次上限の判定と同時に行うため(下記)。
 *
 * ⭐ 日次上限の判定もここで行う。会話作成時の「最初の質問」(StoreAction 経由)と
 *    通常の送信(AiChatController::storeMessage)の**両方が必ず通るのはこの Action だけ**で、
 *    Controller 側に書くと片方を書き漏らして上限をすり抜ける経路ができる。
 *    ⚠️ 数えてから INSERT するまでに他のリクエストが割り込めると、同時送信で上限を超えて
 *    Gemini を呼べてしまう(レビューで指摘された TOCTOU)。users の行を lockForUpdate() で
 *    押さえて同じ受講生の送信を直列化する。
 *    手本は app/UseCases/MeetingQuota/ConsumeQuotaAction.php:36-39 ——
 *    「残数集計の SELECT → INSERT の間に他リクエストが INSERT を完了させる TOCTOU を防ぐ」という
 *    まったく同じ形で、同じく User 行をミューテックスに使っている。
 *    ⚠️ **ロックの取得を count より前に置くことが本質。** 順番を入れ替えると保護が消える。
 */
final class StoreMessageAction
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly AiChatContextService $context,
    ) {}

    /**
     * @return array{user: AiChatMessage, assistant: AiChatMessage}
     *
     * @throws DailyMessageLimitExceededException 日次上限に到達。行を 1 つも作らずに投げる
     * @throws GeminiNotConfiguredException API キー未設定。**行を 1 つも作らずに投げる**
     * @throws GeminiRequestFailedException AI の失敗。行は作り終えてから投げ直す
     */
    public function __invoke(AiChatConversation $conversation, string $content): array
    {
        // 環境の不備は「何度送り直しても直らない」ので、会話にエラーの行を積まない。
        // 呼び出し側は案内だけを返す(GeminiNotConfiguredException の docblock 参照)。
        if (! $this->gemini->isConfigured()) {
            throw new GeminiNotConfiguredException;
        }

        // ① 入力の組み立て。保存より先(上の ⚠️ を参照)。まだ何も書き込まない。
        $payload = $this->context->build($conversation, $content);

        $limit = (int) config('ai-chat.daily_message_limit');

        // ②③ 上限の判定と 2 行の保存を 1 つの短いトランザクションに閉じる。
        //    ⚠️ Gemini の呼び出し(④)はこの外。ここに入れると AI の失敗で質問ごと消える。
        [$userMessage, $assistantMessage] = DB::transaction(function () use ($conversation, $content, $limit): array {
            // 同じ受講生の同時送信を直列化する。数えてから INSERT するまでの割り込みを防ぐ。
            // ⚠️ この行は必ず下の count より前。firstOrFail() にして「ロックが取れなかった」を握り潰さない。
            User::query()->whereKey($conversation->user_id)->lockForUpdate()->firstOrFail();

            // 上限の判定は Gemini を呼ぶ前に。呼んでから数えると、超過分の費用だけが発生する。
            if ($this->sentTodayCount($conversation->user_id) >= $limit) {
                throw new DailyMessageLimitExceededException($limit);
            }

            // ② 受講生の発言。応答を待つ必要が無いので最初から Completed。
            $userMessage = $conversation->messages()->create([
                'role' => AiChatMessageRole::User,
                'content' => $content,
                'status' => AiChatMessageStatus::Completed,
            ]);

            // ③ AI の応答の器。本文は空のまま Pending
            //    (支給 Blade message-bubble.blade.php:45 が「Pending かつ本文が空」で
            //     ローディングのドットを出す)。
            $assistantMessage = $conversation->messages()->create([
                'role' => AiChatMessageRole::Assistant,
                'content' => '',
                'status' => AiChatMessageStatus::Pending,
            ]);

            return [$userMessage, $assistantMessage];
        });

        try {
            // ④ ここが失敗しても、②③の行は残る。
            $result = $this->gemini->generate($payload['messages'], $payload['system_prompt']);
        } catch (GeminiRequestFailedException $e) {
            // ⑤-失敗。error_detail には HTTP ステータス番号を含む文字列を入れる ——
            //    支給 Blade message-bubble.blade.php:55-56 がその番号で文言を出し分ける。
            $assistantMessage->update([
                'status' => AiChatMessageStatus::Error,
                'error_detail' => $e->detail(),
            ]);

            // 呼び出し側(Controller)が 502 + upstream_status に変換する
            // (支給 JS resources/js/ai-chat/chat-client.js:57-61)。
            throw $e;
        }

        // ⑤-成功
        $assistantMessage->update([
            'content' => $result['text'],
            'status' => AiChatMessageStatus::Completed,
        ]);

        return [
            'user' => $userMessage,
            'assistant' => $assistantMessage,
        ];
    }

    /**
     * その受講生が今日送ったメッセージの数。
     *
     * ⭐ 数えるのは**受講生が送った行(role = user)だけ**。原典の非機能要件が
     *    「受講生 1 人あたりの 1 日の**送信回数**」と書いており、AI の応答は受講生の送信ではない。
     *
     * ⭐ この数え方だと、原典スコープ外「AI 失敗時の Rate Limit クォータ補正
     *    (**失敗分も日次カウント**)」が自動的に満たされる —— 受講生の発言の行は
     *    AI が失敗しても必ず残るため(この Action の ② が ④ より先にあるから)。
     *    逆に「AI の完了応答」を数えると、失敗した回が消費されず、原典が明示的に
     *    否定した「補正」になってしまう。
     *
     * ⚠️ 暦日で区切る。支給 JS の文言が「明日 0:00 以降に再度ご利用ください」
     *    (resources/js/ai-chat/floating-widget.js:156)なので、24 時間のスライド窓にしない。
     *    アプリのタイムゾーンは Asia/Tokyo(config/app.php:83)。
     */
    private function sentTodayCount(string $userId): int
    {
        return AiChatMessage::query()
            ->where('role', AiChatMessageRole::User)
            ->whereDate('created_at', today())
            ->whereHas('aiChatConversation', fn ($q) => $q->where('user_id', $userId))
            ->count();
    }
}
