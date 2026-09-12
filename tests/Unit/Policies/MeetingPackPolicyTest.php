<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\MeetingPack;
use App\Models\User;
use App\Policies\MeetingPackPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 面談パックの認可ポリシーを単体で検証する。
 *
 * Feature テストは「画面を開いて 403 か」を見るのに対し、ここは Policy の戻り値そのものを固定する。
 * 8 メソッドのどれか1つを書き換えても、この2本のどちらかが落ちる。
 *
 * 手本: tests/Unit/Policies/CertificationCategoryPolicyTest.php
 */
class MeetingPackPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** 管理者は8つの操作すべてを許可される */
    public function test_admin_can_perform_all_meeting_pack_operations(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->create();
        $policy = new MeetingPackPolicy;

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
    public function test_coach_and_student_cannot_manage_meeting_packs(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $plan = MeetingPack::factory()->create();
        $policy = new MeetingPackPolicy;

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
        // Arrange: 3状態を用意する
        $admin = User::factory()->admin()->create();
        $policy = new MeetingPackPolicy;

        // Assert: どの状態でも publish / archive / unarchive はすべて true を返す。
        // 「下書きからしか公開できない」は Action 側で拒否しており、Policy は権限だけを見る。
        //
        // ⚠️ 将来 Policy 側にも状態の条件を持たせたくなったら(例: 公開中は delete を false にする)、
        // この行が落ちる。そのときは支給 Blade の @if(状態判定)と役割が二重になるので、
        // どちらに寄せるかを決めてから直すこと。単にこのテストを緩めるのは筋が悪い
        foreach (['draft', 'published', 'archived'] as $state) {
            $plan = MeetingPack::factory()->{$state}()->create();

            $this->assertTrue($policy->publish($admin, $plan));
            $this->assertTrue($policy->archive($admin, $plan));
            $this->assertTrue($policy->unarchive($admin, $plan));
            $this->assertTrue($policy->delete($admin, $plan));
        }
    }
}
