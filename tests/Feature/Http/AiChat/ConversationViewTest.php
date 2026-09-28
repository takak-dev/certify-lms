<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Enums\AiChatMessageRole;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI 相談の「読み取り経路」を検証する(S-A-02)。
 *
 * 対象は 2 本のルート。
 *   - GET /ai-chat                          … 最新の会話へ送るか、0 件なら空状態
 *   - GET /ai-chat/conversations/{会話}      … 会話 1 件。HTML と JSON の 2 つの顔を持つ
 *
 * ⚠️ この機能は**学習中の受講生専用**(原典 スコープ外「コーチ / 管理者による利用」)。
 *    弾く役は 3 つに分かれているので、それぞれ別のテストで確かめる。
 *      role:student ミドルウェア … コーチ / 管理者
 *      active-learning ミドルウェア … 修了 / 退会 / 招待中の受講生
 *      AiChatConversationPolicy   … 他人の会話
 */
class ConversationViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        // Act
        $response = $this->get(route('ai-chat.index'));

        // Assert: 未ログインは他の画面と同じくログインへ
        $response->assertRedirect(route('login'));
    }

    public function test_index_shows_empty_state_when_student_has_no_conversation(): void
    {
        // Arrange: 会話を 1 件も持たない学習中の受講生
        $student = User::factory()->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($student)->get(route('ai-chat.index'));

        // Assert: 支給の空状態 Blade が出る(リダイレクトしない)
        $response->assertOk();
        $response->assertViewIs('ai-chat.empty-state');
        $response->assertSee('まだ相談履歴はありません');
    }

    public function test_index_redirects_to_the_most_recent_conversation(): void
    {
        // Arrange: 最終発言の新しい会話 / 古い会話を 1 件ずつ。
        //          並び替えの基準は last_message_at(支給 Blade の履歴サイドバーと同じ列)なので、
        //          作成順ではなくこの列で「新しい方」が選ばれることを確かめたい。
        //          そこで**古い方を後に作る**——作成順で選んでいたら落ちるように仕向ける。
        $student = User::factory()->student()->inProgress()->create();
        $newer = AiChatConversation::factory()
            ->withMessageAt(now()->subHour())
            ->create(['user_id' => $student->id]);
        AiChatConversation::factory()
            ->withMessageAt(now()->subDays(3))
            ->create(['user_id' => $student->id]);

        // Act
        $response = $this->actingAs($student)->get(route('ai-chat.index'));

        // Assert
        $response->assertRedirect(route('ai-chat.conversations.show', $newer));
    }

    public function test_index_does_not_pick_up_another_students_conversation(): void
    {
        // Arrange: 他人の会話だけが存在する状態
        $student = User::factory()->student()->inProgress()->create();
        AiChatConversation::factory()->withMessageAt(now())->create();

        // Act
        $response = $this->actingAs($student)->get(route('ai-chat.index'));

        // Assert: 自分の会話は 0 件なので空状態(他人の会話へ飛ばされない)
        $response->assertOk();
        $response->assertViewIs('ai-chat.empty-state');
    }

    public function test_show_displays_messages_in_chronological_order(): void
    {
        // Arrange: わざと「新しい発言を先に作る」。
        //          並び順の指定を忘れると主キー(ULID)順になり、この順で出てしまう。
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        AiChatMessage::factory()->fromAssistant()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'content' => 'あとの発言',
            'created_at' => now(),
        ]);
        AiChatMessage::factory()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'content' => 'さきの発言',
            'created_at' => now()->subMinutes(5),
        ]);

        // Act
        $response = $this->actingAs($student)->get(route('ai-chat.conversations.show', $conversation));

        // Assert: 古い発言が先に描画される
        $response->assertOk();
        $response->assertViewIs('ai-chat.show');
        $response->assertSeeInOrder(['さきの発言', 'あとの発言']);
    }

    public function test_show_returns_json_when_the_client_asks_for_it(): void
    {
        // Arrange: ウィジェットが開いたときの履歴復元と同じ状況
        //          (resources/js/ai-chat/floating-widget.js:177-192 が data.messages を読む)
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        AiChatMessage::factory()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'content' => '質問の本文',
        ]);

        // Act: 支給 JS と同じヘッダを付けて叩く
        $response = $this->actingAs($student)
            ->getJson(route('ai-chat.conversations.show', $conversation));

        // Assert: JS が読む形で返る
        $response->assertOk();
        $response->assertJsonPath('conversation.id', $conversation->id);
        $response->assertJsonPath('messages.0.content', '質問の本文');
        $response->assertJsonPath('messages.0.role', AiChatMessageRole::User->value);
    }

    public function test_json_does_not_expose_observability_metadata(): void
    {
        // Arrange
        // 原典の非機能要件「運用観測メタデータ(モデル名 / トークン数 / 応答時間)は
        // 受講生には表示しない」を守れているかの見張り(decisions #233)。
        // ⚠️ 支給 JS resources/js/ai-chat/message-renderer.js:88-89 は
        //    「キーに値が入っていれば表示する」ので、**返した瞬間に原典違反になる**。
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        AiChatMessage::factory()->fromAssistant()->create([
            'ai_chat_conversation_id' => $conversation->id,
        ]);

        // Act
        $response = $this->actingAs($student)
            ->getJson(route('ai-chat.conversations.show', $conversation));

        // Assert: 3 つのキーが 1 つも含まれない
        $response->assertOk();
        $response->assertJsonMissingPath('messages.0.model');
        $response->assertJsonMissingPath('messages.0.response_time_ms');
        $response->assertJsonMissingPath('messages.0.output_tokens');
    }

    public function test_message_without_explicit_status_still_renders(): void
    {
        // Arrange
        // 回帰テスト。AI 応答の行は「status を渡さず Pending として作る」設計なので、
        // Factory を通さずに create() する経路を再現する。
        // ⚠️ migration の default('pending') は DB が INSERT 時に埋めるもので、
        //    PHP のインスタンスには乗らない。かつて $message->status が null になり、
        //    支給 Blade message-bubble.blade.php:17 の `$message->status->value` が落ちた。
        //    AiChatMessage の $attributes がそれを防いでいる。
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        AiChatMessage::create([
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::Assistant,
            'content' => '',
        ]);

        // Act
        $response = $this->actingAs($student)->get(route('ai-chat.conversations.show', $conversation));

        // Assert: 落ちずに描画され、応答待ちの状態が DOM に出る
        $response->assertOk();
        $response->assertSee('data-message-status="pending"', false);
    }

    public function test_the_widget_is_rendered_for_a_learning_student(): void
    {
        // Arrange
        // 原典 要件「学習中受講生の全画面の右下に常駐する」。
        // ⚠️ 消えることの検査(FeatureSwitchTest)しか無いと、「出る」側が壊れても気づけない。
        //    decisions #236 は、ルート未登録のままこれが描画されて全画面が 500 になった実測を残している。
        $student = User::factory()->student()->inProgress()->create();

        // Act: AI 相談とは関係のない画面を開く
        $response = $this->actingAs($student)->get(route('dashboard.index'));

        // Assert: 支給ウィジェットの土台が描かれている
        $response->assertOk();
        $response->assertSee('data-ai-chat-widget', false);
    }

    public function test_the_widget_is_not_rendered_on_the_ai_chat_screens(): void
    {
        // Arrange
        // 原典 要件「学習中受講生の全画面の右下に常駐する(**対象外のロール・状態や AI 相談画面では
        // 表示しない**)」。フル画面の中にウィジェットが重なって出ないようにする。
        //
        // ⚠️ この要件は支給 Blade resources/views/layouts/app.blade.php:64 の
        //    `! request()->routeIs('ai-chat.*')` が担っており、**我々が付けたルート名が
        //    `ai-chat.` で始まること**で成立している。支給 Blade は変えられないので、
        //    ルート名を 1 文字変えるとこの要件が静かに壊れる。ここで固定しておく。
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        // Act & Assert: フル画面(ai-chat.conversations.show)
        $this->actingAs($student)
            ->get(route('ai-chat.conversations.show', $conversation))
            ->assertOk()
            ->assertDontSee('data-ai-chat-widget', false);

        // Act & Assert: 空状態(ai-chat.index)。会話を持たない別の受講生で開く
        $withoutConversation = User::factory()->student()->inProgress()->create();
        $this->actingAs($withoutConversation)
            ->get(route('ai-chat.index'))
            ->assertOk()
            ->assertDontSee('data-ai-chat-widget', false);
    }

    public function test_the_widget_is_not_rendered_for_a_coach(): void
    {
        // Arrange: 原典 スコープ外「コーチ / 管理者による AI 相談機能の利用」
        $coach = User::factory()->coach()->inProgress()->create();

        // Act
        $response = $this->actingAs($coach)->get(route('dashboard.index'));

        // Assert
        $response->assertOk();
        $response->assertDontSee('data-ai-chat-widget', false);
    }

    public function test_another_student_cannot_open_the_conversation(): void
    {
        // Arrange: 会話のオーナーと、無関係な受講生
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);

        // Act
        $response = $this->actingAs($other)->get(route('ai-chat.conversations.show', $conversation));

        // Assert: Policy が弾く
        $response->assertForbidden();
    }

    public function test_coach_cannot_open_the_conversation(): void
    {
        // Arrange
        // 原典 スコープ外「他受講生の会話履歴閲覧(管理者 / コーチ含む)」。
        // ここで落ちるのは role:student ミドルウェア(Policy まで届かない)。
        $coach = User::factory()->coach()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create();

        // Act
        $response = $this->actingAs($coach)->get(route('ai-chat.conversations.show', $conversation));

        // Assert
        $response->assertForbidden();
    }

    public function test_admin_cannot_open_the_conversation(): void
    {
        // Arrange: 管理者にも特権を与えない(QaThreadPolicy::delete とはここが違う)
        $admin = User::factory()->admin()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create();

        // Act
        $response = $this->actingAs($admin)->get(route('ai-chat.conversations.show', $conversation));

        // Assert
        $response->assertForbidden();
    }

    public function test_graduated_student_cannot_use_ai_chat(): void
    {
        // Arrange
        // 修了した受講生はログインできるが、プラン機能には進めない。
        // 弾くのは active-learning ミドルウェア
        // (app/Http/Middleware/EnsureActiveLearning.php:15 が ai-chat を名指ししている)。
        $graduated = User::factory()->student()->graduated()->create();

        // Act
        $response = $this->actingAs($graduated)->get(route('ai-chat.index'));

        // Assert
        $response->assertForbidden();
    }
}
