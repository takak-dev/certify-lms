<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Section;
use Illuminate\Support\Facades\Gate;

/**
 * Gemini へ渡す入力を組み立てる Service(S-A-02)。
 *
 * ⭐ **AI に何を見せるかを決める場所は、このクラスだけ。** 会話モデルからは氏名もメールも
 *    模試の点数もたどれてしまうので、組み立てを 1 箇所に閉じ込めて、そこだけを見張る。
 *
 * decisions #199 が送ってよいものを 4 つに限定している。
 *   ① システム指示(config('ai-chat.system_prompt'))
 *   ② 受講中の資格名
 *   ③ 教材から始めた会話のみ —— Section のタイトルと本文の先頭一定文字数
 *   ④ 直近のやり取り(config('ai-chat.history_limit') 件)
 * **氏名・メールアドレス・学習進捗・模試の点数は送らない。**
 *
 * ⚠️ 支給ウィジェットの @props が 3 つしかないこと(resources/views/components/ai-chat/
 *    floating-widget.blade.php:8-10)は「ブラウザから送れない」ことしか保証しない。
 *    サーバ側には何の制約も無いので、**この制限を守るのはこのクラスの責任**。
 *    見張りは tests/Unit/Services/AiChatContextServiceTest.php にある。
 *
 * ⚠️ 本文を先頭だけにするのは原典スコープ外「教材本文埋め込みは外部 API 無料枠を
 *    圧迫するため見送り」に沿う(完全な RAG は作らない)。
 */
class AiChatContextService
{
    /**
     * 会話と今回の質問から、GeminiService::generate() に渡す 2 つを作る。
     *
     * @return array{
     *     system_prompt: string,
     *     messages: array<int, array{role: AiChatMessageRole, text: string}>
     * }
     */
    public function build(AiChatConversation $conversation, string $newMessage): array
    {
        return [
            'system_prompt' => $this->buildSystemPrompt($conversation),
            'messages' => $this->buildMessages($conversation, $newMessage),
        ];
    }

    /**
     * ① システム指示に ② 資格名 と ③ 教材の抜粋 を足したもの。
     *
     * 文脈を contents(会話本体)ではなくシステム指示側に置く理由は 2 つ。
     *   - 会話本体を「受講生と AI の発言だけ」に保てる(履歴の件数制御が素直になる)
     *   - 受講生が送っていない文章が、受講生の発言として混ざらない
     */
    private function buildSystemPrompt(AiChatConversation $conversation): string
    {
        $parts = [(string) config('ai-chat.system_prompt')];

        // ② 受講中の資格名。会話に紐づく受講登録から取る。
        //    学習中 / 合格の受講登録だけを見るのは、支給 Blade のコンテキストバッジと同じ基準
        //    (resources/views/ai-chat/_partials/context-badges.blade.php:12-17)。
        $enrollment = $conversation->enrollment;
        $certificationName = $enrollment !== null
            && in_array($enrollment->status, [EnrollmentStatus::Learning, EnrollmentStatus::Passed], true)
            ? $enrollment->certification?->name
            : null;

        if (filled($certificationName)) {
            $parts[] = "受講生が目指している資格: {$certificationName}";
        }

        // ③ 教材から始めた会話のみ。タイトルと本文の先頭だけを添える。
        $section = $this->visibleSection($conversation);

        if ($section !== null) {
            $excerpt = mb_substr(
                (string) $section->body,
                0,
                (int) config('ai-chat.section_excerpt_length'),
            );

            $parts[] = "受講生が読んでいる教材のタイトル: {$section->title}";
            $parts[] = "その教材の冒頭(抜粋):\n{$excerpt}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * 教材の文脈を**いま**添えてよいか確かめ、よければその Section を返す。
     *
     * ⚠️ 会話を作った時点の認可(AiChatController::resolveSection)だけでは足りない。
     *    作成後に次のどちらかが起きると、権限を失ったあとも本文が Gemini に流れ続ける
     *    (S-A-02 のレビューで判明。decisions #244)。
     *      ① 管理者が教材を非公開に戻す(app/UseCases/Section/UnpublishAction.php)
     *      ② 受講登録が failed になる(app/UseCases/Enrollment/FailAction.php)。
     *         ⚠️ このとき UserStatus は in_progress のままなので active-learning は通る
     *
     * 権限を失ったときは 403 にせず**教材の文脈だけ外して会話は続ける**。原典は会話を消すことも
     * 止めることも求めておらず、「失敗しても質問は残る」方針とも整合する。
     *
     * ⚠️ **ここで守るのは「Gemini へ送る本文」だけ。** 画面のコンテキストバッジ(📚)に出る
     *    教材タイトルは落としていない(ShowAction が section を無条件に eager load するため)。
     *    意図的な線引きで、理由は 2 つ —— ①タイトルは受講生が会話を作った時点で正当に見た情報で、
     *    本文のように新しく漏れるものではない ②落とすと「自分の会話の見出しが急に消える」挙動になり、
     *    会話の記録としてかえって読みにくい。**タイトルまで隠したいなら ShowAction 側で
     *    setRelation('section', null) する**(3 周目のレビューで挙がった選択肢)。
     */
    private function visibleSection(AiChatConversation $conversation): ?Section
    {
        $section = $conversation->section;

        if ($section === null) {
            return null;
        }

        // 資格 / パート / 章 / セクションの 4 段すべてが公開されているか
        if (! Section::studentVisible()->whereKey($section->id)->exists()) {
            return null;
        }

        // その受講生がいまもその資格を受講しているか(教材閲覧と同じ Gate)
        return Gate::forUser($conversation->user)->allows('learning.section.view', $section)
            ? $section
            : null;
    }

    /**
     * ④ 直近のやり取り + 今回の質問。古い順に並べる。
     *
     * ⚠️ 本文が空の行(応答待ち / 失敗した応答)は外す。Gemini の parts.text は空文字を
     *    受け付けず、そのまま送るとリクエストごと弾かれる。
     *    「失敗した応答も履歴に残す」(原典)ことと「AI にそれを見せる」ことは別。
     *
     * ⚠️ 件数だけで頭打ちにする。原典スコープ外「自前のトークン数切り詰め —— 履歴件数のみで制御」。
     *
     * @return array<int, array{role: AiChatMessageRole, text: string}>
     */
    private function buildMessages(AiChatConversation $conversation, string $newMessage): array
    {
        $limit = (int) config('ai-chat.history_limit');

        $history = $conversation->messages()
            ->where('status', AiChatMessageStatus::Completed)
            ->where('content', '!=', '')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->sortBy('created_at')
            ->values()
            ->map(fn (AiChatMessage $m): array => [
                'role' => $m->role,
                'text' => $m->content,
            ])
            ->all();

        $history[] = [
            'role' => AiChatMessageRole::User,
            'text' => $newMessage,
        ];

        return $history;
    }
}
