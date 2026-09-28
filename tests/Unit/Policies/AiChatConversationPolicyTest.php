<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\AiChatConversation;
use App\Models\User;
use App\Policies\AiChatConversationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI 相談の会話に対する認可を、ロール × ability の表として固定する(S-A-02)。
 *
 * HTTP 経由の 403 は Feature テスト側で押さえているが、こちらは Policy を直接呼んで
 * 「誰が何をできるか」を 1 枚の表にする。**管理者・コーチに特権が無いこと**が要点で、
 * 原典スコープ外「他受講生の会話履歴閲覧(管理者 / コーチ含む)— プライバシー / 監査外」に対応する。
 *
 * ⚠️ QaThreadPolicy::delete() は管理者にモデレーション権限を与えている。流用すると原典違反になる。
 */
class AiChatConversationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private AiChatConversationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AiChatConversationPolicy;
    }

    /**
     * オーナーの会話 1 件と、比較対象のユーザーたちを用意する。
     *
     * @return array{0: AiChatConversation, 1: User, 2: User, 3: User, 4: User}
     *                                                                          会話 / オーナー / 別の受講生 / コーチ / 管理者
     */
    private function makeActors(): array
    {
        $owner = User::factory()->student()->inProgress()->create();

        return [
            AiChatConversation::factory()->create(['user_id' => $owner->id]),
            $owner,
            User::factory()->student()->inProgress()->create(),
            User::factory()->coach()->inProgress()->create(),
            User::factory()->admin()->inProgress()->create(),
        ];
    }

    public function test_owner_can_do_everything(): void
    {
        // Arrange
        [$conversation, $owner] = $this->makeActors();

        // Assert: オーナーは 4 つの ability すべてを通る
        $this->assertTrue($this->policy->view($owner, $conversation));
        $this->assertTrue($this->policy->update($owner, $conversation));
        $this->assertTrue($this->policy->delete($owner, $conversation));
        $this->assertTrue($this->policy->createMessage($owner, $conversation));
    }

    public function test_another_student_can_do_nothing(): void
    {
        // Arrange
        [$conversation, , $other] = $this->makeActors();

        // Assert
        $this->assertFalse($this->policy->view($other, $conversation));
        $this->assertFalse($this->policy->update($other, $conversation));
        $this->assertFalse($this->policy->delete($other, $conversation));
        $this->assertFalse($this->policy->createMessage($other, $conversation));
    }

    public function test_coach_has_no_privilege(): void
    {
        // Arrange
        [$conversation, , , $coach] = $this->makeActors();

        // Assert: 会話を始めることすらできない
        $this->assertFalse($this->policy->create($coach));
        $this->assertFalse($this->policy->view($coach, $conversation));
        $this->assertFalse($this->policy->update($coach, $conversation));
        $this->assertFalse($this->policy->delete($coach, $conversation));
        $this->assertFalse($this->policy->createMessage($coach, $conversation));
    }

    public function test_admin_has_no_privilege_either(): void
    {
        // Arrange
        // ⭐ ここがこのテストの主眼。管理者に例外を作ると原典スコープ外に違反する。
        [$conversation, , , , $admin] = $this->makeActors();

        // Assert
        $this->assertFalse($this->policy->create($admin));
        $this->assertFalse($this->policy->view($admin, $conversation));
        $this->assertFalse($this->policy->update($admin, $conversation));
        $this->assertFalse($this->policy->delete($admin, $conversation));
        $this->assertFalse($this->policy->createMessage($admin, $conversation));
    }

    public function test_any_student_can_start_a_conversation(): void
    {
        // Arrange: create はモデルを取らない(まだ会話の行が無いため)
        $student = User::factory()->student()->inProgress()->create();

        // Assert
        $this->assertTrue($this->policy->create($student));
    }

    public function test_message_ability_is_delegated_to_view(): void
    {
        // Arrange
        // createMessage は view と同じ条件でなければならない。
        // 「読めないのに書ける」「読めるのに書けない」状態を作らないための固定。
        [$conversation, $owner, $other] = $this->makeActors();

        // Assert
        $this->assertSame(
            $this->policy->view($owner, $conversation),
            $this->policy->createMessage($owner, $conversation),
        );
        $this->assertSame(
            $this->policy->view($other, $conversation),
            $this->policy->createMessage($other, $conversation),
        );
    }

    public function test_no_admin_override_hook_exists(): void
    {
        // Assert
        // Policy の before() は「全 ability を一括許可する」抜け道になる。
        // 将来生やされていないことを機械で見張る(手本: ChatRoomPolicyTest の method_exists 固定)。
        $this->assertFalse(
            method_exists(AiChatConversationPolicy::class, 'before'),
            '管理者を一括許可する before() を生やしてはいけない(原典スコープ外)',
        );
    }
}
