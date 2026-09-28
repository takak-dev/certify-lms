<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AiChat\GeminiNotConfiguredException;
use App\Http\Requests\AiChat\StoreConversationRequest;
use App\Http\Requests\AiChat\StoreMessageRequest;
use App\Http\Requests\AiChat\UpdateConversationRequest;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Section;
use App\Services\GeminiService;
use App\UseCases\AiChat\DestroyAction;
use App\UseCases\AiChat\GenerateTitleAction;
use App\UseCases\AiChat\ShowAction;
use App\UseCases\AiChat\StoreAction;
use App\UseCases\AiChat\StoreMessageAction;
use App\UseCases\AiChat\UpdateTitleAction;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * AI 相談(S-A-02)の Controller。学習中の受講生専用
 * (role:student + active-learning はルート側のミドルウェアが担当する)。
 *
 * - index       : 最新の会話へ redirect。0 件なら空状態を表示(手本: ChatRoomController::index)
 * - store       : 会話の作成 / 再開(#201。JSON では 200 = 再開 / 201 = 新規)
 * - show        : 会話 1 件。⭐ HTML と JSON の 2 つの顔を持つ(下記)
 * - update      : タイトルの変更(会話オーナーのみ)
 * - destroy     : 会話の削除(会話オーナーのみ)
 * - storeMessage: メッセージ送信と AI 応答
 *
 * 認可の掛け方は既存の流儀に揃える(app/Http/Controllers/EnrollmentGoalController.php:27-28)。
 * FormRequest を受け取るメソッドは Request::authorize() が、受け取らないメソッドは
 * $this->authorize() が Policy を呼ぶ。
 *
 * ⚠️ try-catch を書かない。docs/tickets/_共通ルール.md:51「個別の Controller で try-catch を
 *    書く必要はない」。AI の失敗 / 上限超過 / キー未設定はすべて HTTP 例外
 *    (app/Exceptions/AiChat/ 配下)で、app/Exceptions/Handler.php が応答に変える。
 *    「会話は作れたが最初の質問だけ失敗した」という業務判断は StoreAction が持つ。
 */
class AiChatController extends Controller
{
    /**
     * AI 相談のトップ。過去の相談をすぐ再開できる入口(原典 要件)。
     *
     * 会話があれば最新の 1 件へ送り、無ければ空状態を出す。
     * この「最新へ redirect / 0 件なら empty-state」は chat と同じ形
     * (app/Http/Controllers/ChatRoomController.php:45-58)。
     */
    public function index(Request $request, GeminiService $gemini): Renderable|RedirectResponse
    {
        $this->warnWhenNotConfigured($gemini);

        $latest = $request->user()
            ->aiChatConversations()
            ->orderByDesc('last_message_at')
            ->first();

        if ($latest !== null) {
            return redirect()->route('ai-chat.conversations.show', $latest);
        }

        return view('ai-chat.empty-state');
    }

    /**
     * 会話の作成 / 再開。
     *
     * 経路が 2 つあり、送られてくるものも返す形も違う。
     *   - ウィジェット … source='widget' + section_id / Accept: application/json
     *     → JSON。**200 = 既存会話の再開 / 201 = 新規作成**(decisions #201。
     *       支給 JS resources/js/ai-chat/floating-widget.js:259-260 がそう書かれている)
     *   - フル画面のモーダル … source='full-screen' + message(任意) / 素のフォーム POST
     *     → その会話へ redirect
     */
    public function store(StoreConversationRequest $request, StoreAction $action): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();

        $result = $action(
            $request->user(),
            $this->resolveSection($validated['section_id'] ?? null),
            $validated['message'] ?? null,
        );
        $conversation = $result['conversation'];

        if ($request->expectsJson()) {
            // ⚠️ JSON 経路では $result['error'] を返していない。支給ウィジェットは会話作成の
            //    リクエストに message を積まない(resources/js/ai-chat/floating-widget.js:253-257 は
            //    source と section_id だけ)ので、この経路で error が立つことが無いため。
            //    将来 JSON でも最初の質問を送るなら、ここに error を載せること。
            return response()->json(
                ['conversation' => $this->conversationToArray($conversation->fresh())],
                $result['created'] ? 201 : 200,
            );
        }

        $redirect = redirect()->route('ai-chat.conversations.show', $conversation);

        return $redirect->with(
            $result['error'] === null ? 'success' : 'error',
            $result['error'] ?? '相談を開始しました。',
        );
    }

    /**
     * 会話 1 件。
     *
     * ⭐ 同じ URL が 2 つの顔を持つ。支給 JS がそう作られているため。
     *   - 通常のアクセス            … フル画面の HTML(resources/views/ai-chat/show.blade.php)
     *   - Accept: application/json  … ウィジェットが開いたときの履歴復元用 JSON
     *     (resources/js/ai-chat/floating-widget.js:193 が `data.messages` を読む。
     *      読めなければ sessionStorage を捨てて新規扱いに戻る)
     */
    public function show(Request $request, AiChatConversation $conversation, ShowAction $action, GeminiService $gemini): Renderable|JsonResponse
    {
        $this->authorize('view', $conversation);

        if (! $request->expectsJson()) {
            $this->warnWhenNotConfigured($gemini);
        }

        $conversation = $action($conversation);

        if ($request->expectsJson()) {
            return response()->json([
                'conversation' => $this->conversationToArray($conversation),
                'messages' => $conversation->messages
                    ->map(fn (AiChatMessage $m) => $this->messageToArray($m))
                    ->all(),
            ]);
        }

        return view('ai-chat.show', ['conversation' => $conversation]);
    }

    /**
     * タイトルの変更。支給 Blade resources/views/ai-chat/show.blade.php:143 のモーダルから
     * フォーム POST(+ @method('PATCH'))で届く。JSON では来ないので画面へ戻すだけ。
     *
     * 認可は UpdateConversationRequest::authorize() が済ませている。
     */
    public function update(UpdateConversationRequest $request, AiChatConversation $conversation, UpdateTitleAction $action): RedirectResponse
    {
        $action($conversation, $request->validated());

        return redirect()
            ->route('ai-chat.conversations.show', $conversation)
            ->with('success', 'タイトルを変更しました。');
    }

    /**
     * 会話の削除。削除後は戻る先が無くなるので入口へ送る
     * (ほかの会話が残っていれば index がその最新へ送り直す)。
     */
    public function destroy(Request $request, AiChatConversation $conversation, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $conversation);

        $action($conversation);

        return redirect()
            ->route('ai-chat.index')
            ->with('success', '会話を削除しました。');
    }

    /**
     * メッセージ送信と AI 応答。呼ぶのは支給 JS だけなので JSON で返す。
     *
     * 失敗時の応答コードは例外クラスが持つ(app/Exceptions/AiChat/)。支給 JS
     * resources/js/ai-chat/chat-client.js:48-62 が番号ごとに文言を出し分ける。
     *   - 422 … 入力の文字数違反(FormRequest が自動で返す)
     *   - 429 … 日次上限の超過(DailyMessageLimitExceededException)
     *   - 502 … AI の失敗(GeminiRequestFailedException::render() が upstream_status を添える)
     *   - 503 … API キー未設定(GeminiNotConfiguredException)
     */
    public function storeMessage(
        StoreMessageRequest $request,
        AiChatConversation $conversation,
        StoreMessageAction $action,
        GenerateTitleAction $generateTitle,
    ): JsonResponse {
        $messages = $action($conversation, (string) $request->validated()['content']);

        $generateTitle($conversation);

        return response()->json([
            'user_message' => $this->messageToArray($messages['user']),
            'assistant_message' => $this->messageToArray($messages['assistant']),
            'conversation' => $this->conversationToArray($conversation->fresh()),
        ]);
    }

    /**
     * 教材コンテキストの Section を解決する。
     *
     * ⚠️ **存在確認だけでは足りない。** 受講していない資格の section_id を直接 POST されると、
     *    その教材のタイトルが会話のタイトルとして応答に返り、本文の冒頭が Gemini への入力に載る。
     *    CLAUDE.md §3-7「画面から消したものは URL でも塞ぐ」の対象。
     *
     * 判定は教材閲覧と同じ道具を使う。
     *   - 未公開 … Section::studentVisible() の判定(資格 / パート / 章 / セクションの 4 段)から外れ、
     *     存在を知らせず 404。教材閲覧側の 404 条件と同じ内容
     *     (app/UseCases/Learning/ShowSectionAction.php:39-43)
     *   - 受講していない … learning.section.view Gate が 403(app/Policies/SectionViewPolicy.php:22)。
     *     Gate は app/Http/Controllers/BrowseController.php:59 と同じものを使う
     *
     * ⚠️ 判定の順序は教材閲覧と逆(あちらは authorize が先)。「未公開かつ未受講」のとき
     *    あちらは 403、こちらは 404 を返す。**漏らす情報が少ない側**なので揃えていない。
     */
    private function resolveSection(?string $sectionId): ?Section
    {
        if (blank($sectionId)) {
            return null;
        }

        $section = Section::studentVisible()->findOrFail($sectionId);

        $this->authorize('learning.section.view', $section);

        return $section;
    }

    /**
     * 支給 JS に返す会話の形。
     *
     * `id` はウィジェットが sessionStorage に保存する鍵(floating-widget.js:280)、
     * `title` は自動生成されたタイトルの反映に使う(chat-client.js:72-75)。
     *
     * @return array<string, string|null>
     */
    private function conversationToArray(AiChatConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'section_id' => $conversation->section_id,
        ];
    }

    /**
     * 支給 JS に返すメッセージの形(resources/js/ai-chat/message-renderer.js:37)。
     *
     * ⚠️ docblock に並ぶ `model` / `response_time_ms` / `output_tokens` は**返さない**
     *    (decisions #233)。JS は `if (message.response_time_ms)` と truthy 判定するため
     *    (message-renderer.js:88-89)、キーごと無ければ何も表示されない。
     *    観測記録はログチャネル ai-chat に出す。
     *
     * @return array<string, string|null>
     */
    private function messageToArray(AiChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role->value,
            'content' => $message->content,
            'status' => $message->status->value,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * API キーが無い環境で画面を開いたときの案内(原典の非機能要件)。
     *
     * ⭐ 支給 Blade を 1 行も変えずに文言を出せる唯一の経路 ——
     *    resources/views/layouts/app.blade.php:51 の <x-flash /> が
     *    session('error') を読んでトーストを出す(resources/views/components/flash.blade.php:9)。
     *
     * ⚠️ flash() ではなく now()。flash() だと次のリクエストにも残り、同じ案内が 2 回出る。
     */
    private function warnWhenNotConfigured(GeminiService $gemini): void
    {
        if (! $gemini->isConfigured()) {
            session()->now('error', (new GeminiNotConfiguredException)->getMessage());
        }
    }
}
