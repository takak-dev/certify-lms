<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use App\UseCases\AiChat\FindOrCreateConversationAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 会話の作成 / 再開を検証する(S-A-02)。
 *
 * ⭐ 主役は decisions #201「教材から始めた会話は section_id で既存会話を再開する。
 *    同じ教材では新しい会話を作らない」。支給 JS resources/js/ai-chat/floating-widget.js:259-260 が
 *    「200 = 既存会話再開 / 201 = 新規作成」を前提にしているため、created の真偽が応答コードになる。
 */
class FindOrCreateConversationActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 資格 → パート → 章 → セクション を 1 本つないで返す。
     * 教材の資格をたどって受講登録を引く経路を確かめるために、途中を省略できない。
     */
    private function makeSection(Certification $certification): Section
    {
        $part = Part::factory()->create(['certification_id' => $certification->id]);
        $chapter = Chapter::factory()->create(['part_id' => $part->id]);

        return Section::factory()->create(['chapter_id' => $chapter->id]);
    }

    public function test_creates_a_general_conversation(): void
    {
        // Arrange: 教材も最初の質問も無い(フル画面のモーダルから「開始する」だけ押した状態)
        $user = User::factory()->student()->inProgress()->create();

        // Act
        $result = app(FindOrCreateConversationAction::class)($user);

        // Assert
        $this->assertTrue($result['created']);
        $this->assertNull($result['conversation']->section_id);
        // 仮タイトル。title は NOT NULL なので必ず何かが入る
        $this->assertSame('新しい相談', $result['conversation']->title);
    }

    public function test_uses_the_first_message_as_the_provisional_title(): void
    {
        // Arrange: モーダルで「最初の質問」を書いた場合
        $user = User::factory()->student()->inProgress()->create();

        // Act
        $result = app(FindOrCreateConversationAction::class)($user, null, '  二分探索木の平均比較回数について  ');

        // Assert: 前後の空白は落とす。履歴サイドバーで「何の相談か」が分かる文字列になる
        $this->assertSame('二分探索木の平均比較回数について', $result['conversation']->title);
    }

    public function test_resumes_the_existing_conversation_for_the_same_section(): void
    {
        // Arrange
        // ⭐ #201 の本体。同じ教材で 2 回目を作ろうとしても、既存が返る。
        $certification = Certification::factory()->create();
        $section = $this->makeSection($certification);
        $user = User::factory()->student()->inProgress()->create();

        $first = app(FindOrCreateConversationAction::class)($user, $section);

        // Act
        $second = app(FindOrCreateConversationAction::class)($user, $section);

        // Assert
        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['conversation']->id, $second['conversation']->id);
        $this->assertSame(1, AiChatConversation::count());
    }

    public function test_another_students_conversation_is_not_reused(): void
    {
        // Arrange: 同じ教材でも、持ち主が違えば別の会話
        $certification = Certification::factory()->create();
        $section = $this->makeSection($certification);
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        app(FindOrCreateConversationAction::class)($owner, $section);

        // Act
        $result = app(FindOrCreateConversationAction::class)($other, $section);

        // Assert
        $this->assertTrue($result['created']);
        $this->assertSame(2, AiChatConversation::count());
    }

    public function test_general_conversations_are_never_merged(): void
    {
        // Arrange
        // ⚠️ 再開は教材つきのときだけ。全般相談をまとめてしまうと
        //    「新しい相談を始める」が機能しなくなる。
        $user = User::factory()->student()->inProgress()->create();
        app(FindOrCreateConversationAction::class)($user);

        // Act
        $result = app(FindOrCreateConversationAction::class)($user);

        // Assert
        $this->assertTrue($result['created']);
        $this->assertSame(2, AiChatConversation::count());
    }

    public function test_links_the_enrollment_of_the_sections_certification(): void
    {
        // Arrange
        // 教材から始めた会話は、その教材が属する資格の受講登録に紐づける。
        // 受講生は 2 つの資格を受講しており、既定とは別の資格の教材を開いている。
        $target = Certification::factory()->create();
        $other = Certification::factory()->create();
        $user = User::factory()->student()->inProgress()->create();
        $targetEnrollment = Enrollment::factory()->learning()->create([
            'user_id' => $user->id, 'certification_id' => $target->id,
        ]);
        $otherEnrollment = Enrollment::factory()->learning()->create([
            'user_id' => $user->id, 'certification_id' => $other->id,
        ]);
        $user->update(['default_enrollment_id' => $otherEnrollment->id]);

        // Act
        $result = app(FindOrCreateConversationAction::class)($user, $this->makeSection($target));

        // Assert: 既定の受講登録ではなく、開いている教材の資格の方が選ばれる
        $this->assertSame($targetEnrollment->id, $result['conversation']->enrollment_id);
    }

    public function test_falls_back_to_the_default_enrollment(): void
    {
        // Arrange: 教材を開いていない相談は、既定の受講登録の資格を文脈にする
        $user = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->learning()->create(['user_id' => $user->id]);
        $user->update(['default_enrollment_id' => $enrollment->id]);

        // Act
        $result = app(FindOrCreateConversationAction::class)($user);

        // Assert
        $this->assertSame($enrollment->id, $result['conversation']->enrollment_id);
    }
}
