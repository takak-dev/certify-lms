<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 配信作成フォーム（GET /admin/announcements/create）の検証。
 *
 * 守りたいのは「対象受講生セレクトの中身」。ここに配信できない相手が並ぶと、
 * 管理者は選べたのに配信されない（decisions #48）という体験になる。
 * フォームの候補と実際の配信集合は同じ定義（User::scopeInProgressStudents）を通す約束なので、
 * その約束が守られていることを画面側から固定する。
 */
class FormScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_the_form(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.announcements.create'));

        // Assert
        $response->assertOk();
        $response->assertViewHas('certifications');
        $response->assertViewHas('students');
    }

    public function test_students_and_coaches_are_rejected(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        foreach ([$student, $coach] as $user) {
            // Act & Assert
            $this->actingAs($user)->get(route('admin.announcements.create'))->assertForbidden();
        }
    }

    /**
     * 対象受講生の候補は「受講中の受講生」だけ。
     *
     * コーチが混ざらないことを特に見る。User::canReceiveNotifications() は
     * 「受講中かつ管理者以外」なのでコーチを通してしまい、そちらを使うと候補に並んでしまう。
     * 原典のスコープ外に「コーチ向けの配信」がある以上、ここに現れてはいけない。
     */
    public function test_student_options_contain_only_in_progress_students(): void
    {
        // Arrange: 受講中の受講生 / 修了した受講生 / 受講中のコーチ / 管理者 を 1 人ずつ
        $admin = User::factory()->admin()->create();
        $target = User::factory()->student()->inProgress()->create();
        $graduated = User::factory()->student()->graduated()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.announcements.create'));

        // Assert
        $response->assertOk();
        $response->assertViewHas('students', function ($students) use ($target, $graduated, $coach, $admin): bool {
            $ids = $students->pluck('id')->all();

            return in_array($target->id, $ids, true)
                && ! in_array($graduated->id, $ids, true)
                && ! in_array($coach->id, $ids, true)
                && ! in_array($admin->id, $ids, true);
        });
    }

    /**
     * 対象資格の候補は公開状態で絞らない。
     *
     * 配信対象を決めるのは受講登録であって資格の公開状態ではないため
     * （公開停止した資格にも受講中の受講生は残る）。手本は MockExamController の create。
     */
    public function test_certification_options_are_not_filtered_by_status(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $published = Certification::factory()->published()->create();
        $archived = Certification::factory()->archived()->create();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.announcements.create'));

        // Assert
        $response->assertViewHas('certifications', function ($certifications) use ($published, $archived): bool {
            $ids = $certifications->pluck('id')->all();

            return in_array($published->id, $ids, true) && in_array($archived->id, $ids, true);
        });
    }
}
