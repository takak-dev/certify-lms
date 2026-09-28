<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\GeminiRequestFailedException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 会話の作成と、メッセージ送信の HTTP 応答を検証する(S-A-02)。
 *
 * ⭐ ここで守りたいのは**応答コード**。支給 JS resources/js/ai-chat/chat-client.js:48-62 が
 *    コードごとに違う文言を出すため、番号を間違えると受講生に誤った案内が出る。
 *      422 … 文字数違反 / 502 … AI の失敗(+ upstream_status) / 503 … API キー未設定
 *    会話作成は resources/js/ai-chat/floating-widget.js:259-260 が
 *      200 = 既存会話の再開 / 201 = 新規作成 を前提にしている。
 *
 * ⚠️ Gemini は差し替える。実通信は 1 度も起きない。
 */
#[Group('external')]
#[Group('gemini')]
class SendMessageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param array{text: string}|\Throwable $result
     */
    private function fakeGemini(array|\Throwable $result, bool $configured = true): void
    {
        $this->mock(GeminiService::class, function ($mock) use ($result, $configured) {
            $mock->shouldReceive('isConfigured')->andReturn($configured);
            $expectation = $mock->shouldReceive('generate');
            $result instanceof \Throwable
                ? $expectation->andThrow($result)
                : $expectation->andReturn($result);
        });
    }

    /**
     * 公開済みの教材を 1 件用意する。$student を渡すとその資格に受講登録も作る。
     *
     * ⚠️ 公開は**資格 / パート / 章 / セクションの 4 段**の連鎖で判定される
     *    (app/Models/Section.php の scopeStudentVisible)。Factory の既定はすべて Draft なので、
     *    1 つでも published() を忘れると Controller の 404 に弾かれる。
     *    ⚠️ scopePublished ではない —— あちらは 3 段だけで**資格を見ない**。
     */
    private function makePublishedSection(?User $enrolledStudent = null): Section
    {
        // ⚠️ 資格も published() が要る。判定は資格 / パート / 章 / セクションの 4 段
        //    (app/Models/Section.php の scopeStudentVisible)。Factory の既定はすべて Draft。
        $certification = Certification::factory()->published()->create();

        if ($enrolledStudent !== null) {
            Enrollment::factory()->learning()->create([
                'user_id' => $enrolledStudent->id,
                'certification_id' => $certification->id,
            ]);
        }

        $part = Part::factory()->published()->create(['certification_id' => $certification->id]);
        $chapter = Chapter::factory()->published()->create(['part_id' => $part->id]);

        return Section::factory()->published()->create(['chapter_id' => $chapter->id]);
    }

    public function test_a_section_of_an_unenrolled_certification_is_rejected(): void
    {
        // Arrange
        // ⭐ 認可の穴の見張り。受講していない資格の section_id を直接 POST されると、
        //    その教材のタイトルが応答に返り、本文の冒頭が Gemini への入力に載ってしまう。
        //    判定は教材閲覧と同じ Gate(app/Policies/SectionViewPolicy.php:22)を使う。
        $student = User::factory()->student()->inProgress()->create();
        $section = $this->makePublishedSection();   // 受講登録を作らない

        // Act
        $response = $this->actingAs($student)->postJson(route('ai-chat.conversations.store'), [
            'source' => 'widget',
            'section_id' => $section->id,
        ]);

        // Assert: 会話も作られない
        $response->assertForbidden();
        $this->assertSame(0, AiChatConversation::count());
    }

    public function test_a_section_of_an_unpublished_certification_is_not_found(): void
    {
        // Arrange
        // ⭐ scopeStudentVisible が scopePublished に足した「資格の公開状態」の見張り。
        //    ⚠️ これが無いと、AiChatController::resolveSection() を published() に戻しても
        //    テストが 1 本も落ちない(3 周目のレビューで指摘された)。
        //    パート / 章 / セクションはすべて公開し、**資格だけ**を非公開にする。
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->archived()->create();
        Enrollment::factory()->learning()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);
        $part = Part::factory()->published()->create(['certification_id' => $certification->id]);
        $chapter = Chapter::factory()->published()->create(['part_id' => $part->id]);
        $section = Section::factory()->published()->create(['chapter_id' => $chapter->id]);

        // Act
        $response = $this->actingAs($student)->postJson(route('ai-chat.conversations.store'), [
            'source' => 'widget',
            'section_id' => $section->id,
        ]);

        // Assert: 存在を知らせず 404。会話も作られない
        $response->assertNotFound();
        $this->assertSame(0, AiChatConversation::count());
    }

    public function test_a_draft_section_is_not_found(): void
    {
        // Arrange
        // 未公開(Draft)の教材は存在を知らせず 404。教材閲覧側と同じ扱い
        // (app/Policies/SectionViewPolicy.php:16-18 の方針)。
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        Enrollment::factory()->learning()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);
        $part = Part::factory()->published()->create(['certification_id' => $certification->id]);
        $chapter = Chapter::factory()->published()->create(['part_id' => $part->id]);
        $draft = Section::factory()->create(['chapter_id' => $chapter->id]);   // 既定は Draft

        // Act
        $response = $this->actingAs($student)->postJson(route('ai-chat.conversations.store'), [
            'source' => 'widget',
            'section_id' => $draft->id,
        ]);

        // Assert
        $response->assertNotFound();
        $this->assertSame(0, AiChatConversation::count());
    }

    public function test_widget_gets_201_for_a_new_conversation_and_200_when_resuming(): void
    {
        // Arrange: 教材から相談を始める(ウィジェット経路)
        $student = User::factory()->student()->inProgress()->create();
        $section = $this->makePublishedSection($student);

        // Act & Assert: 1 回目は新規作成
        $first = $this->actingAs($student)->postJson(route('ai-chat.conversations.store'), [
            'source' => 'widget',
            'section_id' => $section->id,
        ]);
        $first->assertStatus(201);

        // Act & Assert: 同じ教材の 2 回目は既存の再開(decisions #201)
        $second = $this->actingAs($student)->postJson(route('ai-chat.conversations.store'), [
            'source' => 'widget',
            'section_id' => $section->id,
        ]);
        $second->assertStatus(200);
        $second->assertJsonPath('conversation.id', $first->json('conversation.id'));
        $this->assertSame(1, AiChatConversation::count());
    }

    public function test_modal_sends_the_first_question_immediately(): void
    {
        // Arrange
        // ⚠️ モーダルの「最初の質問」を保存だけして放置すると、受講生は同じ質問を
        //    自分で送り直す羽目になる(decisions #239)。その場で送信まで済ませる。
        $student = User::factory()->student()->inProgress()->create();
        $this->fakeGemini(['text' => 'AI の回答']);

        // Act: 素のフォーム POST(フル画面のモーダル経路)
        $response = $this->actingAs($student)->post(route('ai-chat.conversations.store'), [
            'source' => 'full-screen',
            'message' => '二分探索木について',
        ]);

        // Assert: 会話へ送られ、質問と回答が保存されている
        $conversation = AiChatConversation::sole();
        $response->assertRedirect(route('ai-chat.conversations.show', $conversation));
        // ⚠️ キーは必ず success(_共通ルール.md:39-40。B-B-07 で実際に踏んだバグ)
        $response->assertSessionHas('success');
        $this->assertSame(2, AiChatMessage::count());
        $this->assertDatabaseHas('ai_chat_messages', ['content' => '二分探索木について']);
        $this->assertDatabaseHas('ai_chat_messages', ['content' => 'AI の回答']);
    }

    public function test_message_returns_the_shape_the_widget_reads(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $this->fakeGemini(['text' => 'AI の回答']);

        // Act
        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '質問です'],
        );

        // Assert: chat-client.js:67-75 が読む 3 つのキー
        $response->assertOk();
        $response->assertJsonPath('user_message.content', '質問です');
        $response->assertJsonPath('assistant_message.content', 'AI の回答');
        $response->assertJsonStructure(['user_message', 'assistant_message', 'conversation' => ['id', 'title']]);
    }

    public function test_too_long_message_is_rejected_with_422(): void
    {
        // Arrange
        // 支給 JS は 422 のときだけ「入力内容を確認してください (1-2000 文字)。」を出す
        // (floating-widget.js:167)。番号を変えるとこの案内が出なくなる。
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $this->fakeGemini(['text' => '届かない']);

        // Act
        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => str_repeat('あ', 2001)],
        );

        // Assert
        $response->assertStatus(422);
        $this->assertSame(0, AiChatMessage::count());
    }

    public function test_ai_failure_returns_502_with_upstream_status(): void
    {
        // Arrange: Gemini がレート制限を返す
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $this->fakeGemini(new GeminiRequestFailedException('Too Many Requests', 429));

        // Act
        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '消えては困る質問'],
        );

        // Assert: 502 + upstream_status(chat-client.js:57-61 が読む)
        $response->assertStatus(502);
        $response->assertJsonPath('upstream_status', 429);

        // Assert: 質問は残り、応答はエラーとして残っている(原典)
        $this->assertDatabaseHas('ai_chat_messages', ['content' => '消えては困る質問']);
        $this->assertSame(1, AiChatMessage::where('status', AiChatMessageStatus::Error)->count());
    }

    public function test_missing_api_key_returns_503_and_saves_nothing(): void
    {
        // Arrange: 環境の不備。何度送り直しても直らない
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $this->fakeGemini(['text' => '届かない'], configured: false);

        // Act
        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '質問'],
        );

        // Assert: 502 と分ける。行も作らない(履歴を汚さない)
        $response->assertStatus(503);
        $this->assertSame(0, AiChatMessage::count());
    }

    public function test_the_screen_explains_when_the_api_key_is_missing(): void
    {
        // Arrange
        // ⭐ 原典「API キーが未設定の環境では、利用できない旨を案内する」。
        //    支給 Blade を変えられないので、layouts/app.blade.php:51 の <x-flash /> に載せる。
        $student = User::factory()->student()->inProgress()->create();
        $this->fakeGemini(['text' => '届かない'], configured: false);

        // Act
        $response = $this->actingAs($student)->get(route('ai-chat.index'));

        // Assert: 画面は開き、案内が出る
        $response->assertOk();
        $response->assertSee('AI 相談は現在ご利用いただけません。管理者にお問い合わせください。');
    }

    public function test_another_student_cannot_post_into_the_conversation(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);
        $this->fakeGemini(['text' => '届かない']);

        // Act
        $response = $this->actingAs($other)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '割り込み'],
        );

        // Assert: AiChatConversationPolicy::createMessage が弾く
        $response->assertForbidden();
        $this->assertSame(0, AiChatMessage::count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
