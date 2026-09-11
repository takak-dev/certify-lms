<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 配信履歴の詳細（GET /admin/announcements/{announcement}）の検証。
 *
 * この画面は監査の窓口であり、誤配信に気づく導線でもある（decisions #88）。
 * 配信対象・対象名・配信件数がそろって出ることを固定する。
 */
class ShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_the_target_and_the_dispatched_count(): void
    {
        // Arrange: 資格指定で 12 件配信した履歴
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->create(['name' => '基本情報技術者試験']);
        $announcement = Announcement::factory()
            ->forCertification($certification)
            ->createdBy($admin)
            ->dispatched(12)
            ->create(['title' => '教材改訂のお知らせ']);

        // Act
        $response = $this->actingAs($admin)->get(route('admin.announcements.show', $announcement));

        // Assert: 何を・誰に・何件送ったかが 1 画面に出る
        $response->assertOk();
        $response->assertSee('教材改訂のお知らせ');
        $response->assertSee('資格指定');
        $response->assertSee('基本情報技術者試験');
        $response->assertSee('12 件');
    }

    public function test_students_and_coaches_are_rejected(): void
    {
        // Arrange
        $announcement = Announcement::factory()->create();
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        foreach ([$student, $coach] as $user) {
            // Act & Assert
            $this->actingAs($user)->get(route('admin.announcements.show', $announcement))->assertForbidden();
        }
    }

    /**
     * 配信は不可逆（原典「一度配信したお知らせは、再配信 / 編集 / 取消ができない」）。
     * ルートを作らないことで実現しているので、名前が存在しないことをそのまま検査する。
     */
    public function test_no_route_exists_for_editing_or_deleting(): void
    {
        // Act & Assert
        foreach (['edit', 'update', 'destroy'] as $action) {
            $this->assertFalse(
                app('router')->has("admin.announcements.{$action}"),
                "admin.announcements.{$action} が存在します。配信の不可逆性は「ルートを作らない」ことで担保しています。",
            );
        }
    }
}
