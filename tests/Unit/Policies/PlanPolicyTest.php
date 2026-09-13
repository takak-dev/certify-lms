<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Plan;
use App\Models\User;
use App\Policies\PlanPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講プランの認可ポリシーを単体で検証する。
 *
 * Feature テストは「画面を開いて 403 か」を見るのに対し、ここは Policy の戻り値そのものを固定する。
 * 8 メソッドのどれか1つを書き換えても、この3本のどれかが落ちる。
 *
 * 手本: tests/Unit/Policies/MeetingPackPolicyTest.php
 */
class PlanPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** 管理者は8つの操作すべてを許可される */
    public function test_admin_can_perform_all_plan_operations(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();
        $policy = new PlanPolicy;

        // Assert
        $this->assertTrue($policy->viewAny($admin));
        $this->assertTrue($policy->view($admin, $plan));
        $this->assertTrue($policy->create($admin));
        $this->assertTrue($policy->update($admin, $plan));
        $this->assertTrue($policy->delete($admin, $plan));
        $this->assertTrue($policy->publish($admin, $plan));
        $this->assertTrue($policy->archive($admin, $plan));
        $this->assertTrue($policy->unarchive($admin, $plan));
    }

    /** コーチと受講生はどの操作も許可されない */
    public function test_coach_and_student_cannot_manage_plans(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $plan = Plan::factory()->create();
        $policy = new PlanPolicy;

        // Assert
        foreach ([$coach, $student] as $user) {
            $this->assertFalse($policy->viewAny($user));
            $this->assertFalse($policy->view($user, $plan));
            $this->assertFalse($policy->create($user));
            $this->assertFalse($policy->update($user, $plan));
            $this->assertFalse($policy->delete($user, $plan));
            $this->assertFalse($policy->publish($user, $plan));
            $this->assertFalse($policy->archive($user, $plan));
            $this->assertFalse($policy->unarchive($user, $plan));
        }
    }

    /** 状態が変わっても判定は変わらない(状態の正しさは Action の担当) */
    public function test_policy_does_not_depend_on_status(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $policy = new PlanPolicy;

        // Assert: どの状態でも publish / archive / unarchive / delete はすべて true を返す。
        // 「下書きからしか公開できない」「下書きしか削除できない」は Action 側で拒否しており、
        // Policy は権限だけを見る。
        //
        // ⚠️ 将来 Policy 側にも状態の条件を持たせたくなったら、この行が落ちる。
        // そのときは支給 Blade の @if(状態判定)と役割が二重になるので、
        // どちらに寄せるかを決めてから直すこと。単にこのテストを緩めるのは筋が悪い
        foreach (['draft', 'published', 'archived'] as $state) {
            $plan = Plan::factory()->{$state}()->create();

            $this->assertTrue($policy->publish($admin, $plan));
            $this->assertTrue($policy->archive($admin, $plan));
            $this->assertTrue($policy->unarchive($admin, $plan));
            $this->assertTrue($policy->delete($admin, $plan));
        }
    }
}
