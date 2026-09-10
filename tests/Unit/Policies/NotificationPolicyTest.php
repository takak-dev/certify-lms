<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\NotificationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NotificationPolicy が「宛先本人かどうか」だけを見ることを固定する単体テスト。
 *
 * HTTP 経由（Feature テスト）はルートのミドルウェアも一緒に効くため、
 * 「Policy そのものが何を許すか」はここで直接叩いて確認する。
 * 手本: tests/Unit/Policies/QaThreadPolicyTest.php
 */
class NotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private NotificationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new NotificationPolicy;
    }

    private function makeNotification(User $for): DatabaseNotification
    {
        return $for->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            'data' => ['notification_type' => 'qa_reply_received', 'title' => 'テスト通知'],
        ]);
    }

    public function test_owner_can_mark_as_read(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $notification = $this->makeNotification($owner);

        // Act & Assert
        $this->assertTrue($this->policy->markAsRead($owner, $notification));
    }

    public function test_other_user_cannot_mark_as_read(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $notification = $this->makeNotification($owner);

        // Act & Assert
        $this->assertFalse($this->policy->markAsRead($other, $notification));
    }

    public function test_role_does_not_matter(): void
    {
        // Arrange: 通知の認可はロールを見ない。管理者でも他人の通知は触れない
        $owner = User::factory()->student()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $notification = $this->makeNotification($owner);

        // Act & Assert
        $this->assertFalse($this->policy->markAsRead($admin, $notification));
        // 一方、自分宛なら管理者でも既読にできる
        $this->assertTrue($this->policy->markAsRead($admin, $this->makeNotification($admin)));
    }

    public function test_same_id_on_a_different_model_type_is_rejected(): void
    {
        // Arrange: notifiable_id だけ一致し、notifiable_type が違う通知を作る。
        //          id 値の一致だけで判定していると、別テーブルの同じ id を持つ相手に通ってしまう
        $user = User::factory()->student()->inProgress()->create();
        $notification = $this->makeNotification($user);
        $notification->forceFill(['notifiable_type' => 'App\\Models\\SomeOtherModel'])->save();

        // Act & Assert
        $this->assertFalse($this->policy->markAsRead($user, $notification->fresh()));
    }
}
