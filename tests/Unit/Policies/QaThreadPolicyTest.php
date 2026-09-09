<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use App\Policies\QaThreadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QaThreadPolicy のロール × 操作の表を固定する単体テスト。
 *
 * HTTP 経由（Feature テスト）はルートのミドルウェアも一緒に効くため、
 * 「Policy そのものが何を許すか」はここで直接叩いて確認する。
 * 手本: tests/Unit/Policies/MockExamPolicyTest.php
 */
class QaThreadPolicyTest extends TestCase
{
    use RefreshDatabase;

    private QaThreadPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new QaThreadPolicy;
    }

    /** 公開中の資格に紐づくスレッドを、指定した投稿者で作る */
    private function thread(?User $author = null, string $certificationState = 'published'): QaThread
    {
        $certification = Certification::factory()->{$certificationState}()->create();
        $author ??= User::factory()->student()->create();

        return QaThread::factory()->forUser($author)->forCertification($certification)->create();
    }

    private function assignCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_only_students_can_create_threads(): void
    {
        // 原典「スレッドの管理(投稿は受講生のみ)」
        $this->assertTrue($this->policy->create(User::factory()->student()->create()));
        $this->assertFalse($this->policy->create(User::factory()->coach()->create()));
        $this->assertFalse($this->policy->create(User::factory()->admin()->create()));
    }

    public function test_view_is_limited_by_certification_status_and_coach_assignment(): void
    {
        $author = User::factory()->student()->create();
        $thread = $this->thread($author);
        $certification = $thread->certification;

        $otherStudent = User::factory()->student()->create();
        $assignedCoach = User::factory()->coach()->create();
        $unassignedCoach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $this->assignCoach($certification, $assignedCoach);

        // 公開中の資格：受講生は誰でも閲覧可、コーチは担当のみ、管理者は可
        $this->assertTrue($this->policy->view($author, $thread));
        $this->assertTrue($this->policy->view($otherStudent, $thread));
        $this->assertTrue($this->policy->view($assignedCoach, $thread->fresh()));
        $this->assertFalse($this->policy->view($unassignedCoach, $thread));
        $this->assertTrue($this->policy->view($admin, $thread));
    }

    public function test_unpublished_certification_hides_thread_from_everyone_but_admin(): void
    {
        // 投稿者本人でも見えない（pending Q21 の暫定判断。decisions #71）
        $author = User::factory()->student()->create();
        $thread = $this->thread($author, 'archived');

        $coach = User::factory()->coach()->create();
        $this->assignCoach($thread->certification, $coach);

        $this->assertFalse($this->policy->view($author, $thread));
        $this->assertFalse($this->policy->view($coach, $thread->fresh()));
        $this->assertTrue($this->policy->view(User::factory()->admin()->create(), $thread));
    }

    public function test_update_and_resolve_are_author_only(): void
    {
        // 管理者にも編集・解決マークは許可しない（原典「管理者は内容の編集や解決マークの代行はできない」）
        $author = User::factory()->student()->create();
        $thread = $this->thread($author);
        $otherStudent = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();

        foreach (['update', 'resolve', 'unresolve'] as $ability) {
            $this->assertTrue($this->policy->{$ability}($author, $thread), $ability.': 投稿者は可');
            $this->assertFalse($this->policy->{$ability}($otherStudent, $thread), $ability.': 他人は不可');
            $this->assertFalse($this->policy->{$ability}($admin, $thread), $ability.': 管理者も不可');
        }
    }

    public function test_delete_is_allowed_for_author_and_admin_only(): void
    {
        // 「回答があると削除できない」は業務ルールなので Policy では見ない（DestroyAction が 409。decisions #37）
        $author = User::factory()->student()->create();
        $thread = $this->thread($author);
        $otherStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $this->assertTrue($this->policy->delete($author, $thread));
        $this->assertTrue($this->policy->delete($admin, $thread));
        $this->assertFalse($this->policy->delete($otherStudent, $thread));
        $this->assertFalse($this->policy->delete($coach, $thread));
    }

    public function test_admin_can_delete_thread_of_unpublished_certification(): void
    {
        // モデレーションは公開状態の制限を受けない
        $thread = $this->thread(null, 'archived');

        $this->assertTrue($this->policy->delete(User::factory()->admin()->create(), $thread));
    }
}
