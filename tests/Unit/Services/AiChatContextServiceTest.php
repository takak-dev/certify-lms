<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\AiChatMessageRole;
use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use App\Services\AiChatContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gemini へ渡す入力の組み立てを検証する(S-A-02)。
 *
 * ⭐ このテストの一番の役目は **decisions #199 の見張り** ——
 *    「送ってよいのは ①システム指示 ②資格名 ③教材のタイトルと本文の先頭 ④直近のやり取り の 4 つだけ。
 *      氏名・メールアドレス・学習進捗・模試の点数は送らない」。
 *
 * ⚠️ 「入っていないはず」では確かめたことにならないので、**特徴的な氏名とメールを実データで
 *    持たせたうえで**、組み立てた入力にそれが 1 文字も現れないことを見る。
 */
class AiChatContextServiceTest extends TestCase
{
    use RefreshDatabase;

    /** 検索しやすい、他の文字列と衝突しない値にする。 */
    private const OWNER_NAME = 'ゼッタイニオクラナイ太郎';

    private const OWNER_EMAIL = 'must-not-be-sent@example.test';

    /**
     * 会話 1 件を、指定した文脈つきで用意する。
     */
    private function makeConversation(?Section $section = null, ?Enrollment $enrollment = null): AiChatConversation
    {
        $owner = User::factory()->student()->inProgress()->create([
            'name' => self::OWNER_NAME,
            'email' => self::OWNER_EMAIL,
        ]);

        return AiChatConversation::factory()->create([
            'user_id' => $owner->id,
            'section_id' => $section?->id,
            'enrollment_id' => $enrollment?->id,
        ]);
    }

    /**
     * 「その受講生がいま開ける教材」を 1 件作る。
     *
     * ⚠️ 4 段すべてを published にし、受講登録も作る必要がある。
     *    どれか 1 つでも欠けると教材の文脈は添えられない(送信時に再確認しているため)。
     *
     * @param array<string, string> $attributes Section に与える属性
     */
    private function makeVisibleSection(User $owner, array $attributes = []): Section
    {
        // ⚠️ 資格も published() が要る。Factory の既定は Draft で、資格が非公開だと
        //    Section::studentVisible() の 4 段判定から外れる。
        $certification = Certification::factory()->published()->create();
        Enrollment::factory()->learning()->create([
            'user_id' => $owner->id,
            'certification_id' => $certification->id,
        ]);
        $part = Part::factory()->published()->create(['certification_id' => $certification->id]);
        $chapter = Chapter::factory()->published()->create(['part_id' => $part->id]);

        return Section::factory()->published()->create($attributes + ['chapter_id' => $chapter->id]);
    }

    public function test_personal_information_is_never_included(): void
    {
        // Arrange: 氏名・メールを持つ受講生の、教材つきの会話
        $conversation = $this->makeConversation();
        $section = $this->makeVisibleSection($conversation->user, ['title' => '二分探索木', 'body' => '木の高さは log n です。']);
        $conversation->update(['section_id' => $section->id]);
        AiChatMessage::factory()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'content' => '過去のやり取り',
        ]);

        // Act
        // ⚠️ fresh() が要る(section_id を後から入れたため)。教材の文脈が**実際に載った**状態で
        //    個人情報が混ざらないことを見たいので、ここを抜かすと検査が骨抜きになる。
        $built = (new AiChatContextService)->build($conversation->fresh(), '今回の質問');

        // Assert: 組み立てた全文(システム指示 + 全メッセージ)を 1 本の文字列にして検査する
        $whole = $built['system_prompt'].' '.implode(' ', array_column($built['messages'], 'text'));
        // 前提の確認: 教材の文脈が載っていること(載っていなければ上の検査は無意味)
        $this->assertStringContainsString('二分探索木', $whole);
        $this->assertStringNotContainsString(self::OWNER_NAME, $whole);
        $this->assertStringNotContainsString(self::OWNER_EMAIL, $whole);
    }

    public function test_section_title_and_body_excerpt_are_attached(): void
    {
        // Arrange: 本文が抜粋の上限より長い教材
        config(['ai-chat.section_excerpt_length' => 20]);
        $conversation = $this->makeConversation();
        $section = $this->makeVisibleSection($conversation->user, [
            'title' => '二分探索木',
            // 先頭 20 文字と、それ以降で別の語にして境目を観測できるようにする
            'body' => str_repeat('あ', 20).'ココカラハオクラナイ',
        ]);
        $conversation->update(['section_id' => $section->id]);

        // Act
        // ⚠️ fresh() が要る。section_id を後から入れたので、古いインスタンスは
        //    section リレーションを null のまま抱えている。
        $built = (new AiChatContextService)->build($conversation->fresh(), '質問');

        // Assert: タイトルと先頭 20 文字は入り、それ以降は入らない
        $this->assertStringContainsString('二分探索木', $built['system_prompt']);
        $this->assertStringContainsString(str_repeat('あ', 20), $built['system_prompt']);
        $this->assertStringNotContainsString('ココカラハオクラナイ', $built['system_prompt']);
    }

    public function test_certification_name_is_attached_for_a_learning_enrollment(): void
    {
        // Arrange: 学習中の受講登録に紐づく会話(教材は無し = 資格コンテキスト)
        $certification = Certification::factory()->create(['name' => '基本情報技術者試験']);
        $enrollment = Enrollment::factory()->learning()->create(['certification_id' => $certification->id]);
        $conversation = $this->makeConversation(null, $enrollment);

        // Act
        $built = (new AiChatContextService)->build($conversation, '質問');

        // Assert
        $this->assertStringContainsString('基本情報技術者試験', $built['system_prompt']);
        // 教材の文脈は無いので、教材向けの見出しは出ない
        $this->assertStringNotContainsString('読んでいる教材', $built['system_prompt']);
    }

    public function test_general_conversation_has_only_the_system_prompt(): void
    {
        // Arrange: 教材も受講登録も無い「全般相談」
        $conversation = $this->makeConversation();

        // Act
        $built = (new AiChatContextService)->build($conversation, '質問');

        // Assert: config のシステム指示そのまま
        $this->assertSame((string) config('ai-chat.system_prompt'), $built['system_prompt']);
    }

    public function test_history_is_oldest_first_and_capped_by_count(): void
    {
        // Arrange: 上限 3 件に対して 5 件の履歴を積む
        config(['ai-chat.history_limit' => 3]);
        $conversation = $this->makeConversation();
        foreach (range(1, 5) as $i) {
            AiChatMessage::factory()->create([
                'ai_chat_conversation_id' => $conversation->id,
                'content' => "発言{$i}",
                'created_at' => now()->subMinutes(10 - $i),
            ]);
        }

        // Act
        $built = (new AiChatContextService)->build($conversation, '今回の質問');

        // Assert: 新しい方から 3 件を拾い、古い順に並べ、最後が今回の質問
        $texts = array_column($built['messages'], 'text');
        $this->assertSame(['発言3', '発言4', '発言5', '今回の質問'], $texts);
        $this->assertSame(AiChatMessageRole::User, $built['messages'][3]['role']);
    }

    public function test_section_context_is_dropped_when_the_material_is_unpublished(): void
    {
        // Arrange
        // ⭐ 会話を作った時点の認可だけでは足りないことの見張り。
        //    管理者が教材を非公開に戻しても(app/UseCases/Section/UnpublishAction.php)、
        //    再確認が無いと本文が Gemini に流れ続ける。
        $conversation = $this->makeConversation();
        $section = $this->makeVisibleSection($conversation->user, [
            'title' => '非公開に戻される教材',
            'body' => 'モレテハイケナイ本文',
        ]);
        $conversation->update(['section_id' => $section->id]);
        $section->update(['status' => ContentStatus::Draft->value]);

        // Act
        $built = (new AiChatContextService)->build($conversation->fresh(), '質問');

        // Assert: 教材の文脈だけが外れる(会話は続けられる)
        $this->assertStringNotContainsString('モレテハイケナイ本文', $built['system_prompt']);
        $this->assertStringNotContainsString('非公開に戻される教材', $built['system_prompt']);
        $this->assertSame(['質問'], array_column($built['messages'], 'text'));
    }

    public function test_section_context_is_dropped_when_the_certification_is_unpublished(): void
    {
        // Arrange
        // ⭐ scopeStudentVisible の「4 段目(資格)」の見張り。
        //    ⚠️ これが無いと visibleSection() を published() に戻しても落ちない。
        //    教材そのものは公開されたままで、**資格だけ**が公開停止になる状況を作る。
        $conversation = $this->makeConversation();
        $section = $this->makeVisibleSection($conversation->user, [
            'title' => '資格ごと公開停止になる教材',
            'body' => 'モレテハイケナイ本文',
        ]);
        $conversation->update(['section_id' => $section->id]);
        $section->chapter->part->certification->update([
            'status' => CertificationStatus::Archived->value,
        ]);

        // Act
        $built = (new AiChatContextService)->build($conversation->fresh(), '質問');

        // Assert
        $this->assertStringNotContainsString('モレテハイケナイ本文', $built['system_prompt']);
        $this->assertStringNotContainsString('資格ごと公開停止になる教材', $built['system_prompt']);
    }

    public function test_section_context_is_dropped_when_the_enrollment_is_no_longer_active(): void
    {
        // Arrange
        // ⚠️ 受講登録が failed になっても UserStatus は in_progress のままなので、
        //    active-learning ミドルウェアは通る(app/UseCases/Enrollment/FailAction.php)。
        //    ここで止めないと、受講を終えた資格の教材を読み続けられる。
        $conversation = $this->makeConversation();
        $section = $this->makeVisibleSection($conversation->user, [
            'title' => '受講が終わった資格の教材',
            'body' => 'モレテハイケナイ本文',
        ]);
        $conversation->update(['section_id' => $section->id]);
        $conversation->user->enrollments()->update(['status' => EnrollmentStatus::Failed->value]);

        // Act
        $built = (new AiChatContextService)->build($conversation->fresh(), '質問');

        // Assert
        $this->assertStringNotContainsString('モレテハイケナイ本文', $built['system_prompt']);
        $this->assertStringNotContainsString('受講が終わった資格の教材', $built['system_prompt']);
    }

    public function test_empty_and_failed_messages_are_excluded_from_history(): void
    {
        // Arrange
        // 失敗した応答(本文が空)は履歴に残るが、AI への入力には混ぜない。
        // ⚠️ Gemini の parts.text は空文字を受け付けず、送るとリクエストごと弾かれる。
        $conversation = $this->makeConversation();
        AiChatMessage::factory()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'content' => '残る質問',
            'created_at' => now()->subMinutes(3),
        ]);
        AiChatMessage::factory()->failed()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'created_at' => now()->subMinutes(2),
        ]);
        AiChatMessage::factory()->pending()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'created_at' => now()->subMinute(),
        ]);

        // Act
        $built = (new AiChatContextService)->build($conversation, '再送した質問');

        // Assert: 本文のある発言と今回の質問だけ
        $this->assertSame(['残る質問', '再送した質問'], array_column($built['messages'], 'text'));
    }
}
