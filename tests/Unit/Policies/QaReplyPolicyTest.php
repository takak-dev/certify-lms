<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Policies\QaReplyPolicy;
use App\Policies\QaThreadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QaReplyPolicy のロール × 操作の表を固定する単体テスト。
 *
 * 管理者の扱いが操作ごとに変わる（投稿・編集は不可、モデレーション削除だけ可）ため、
 * HTTP テストからは読み取りにくい。ここで直接叩いて明示する。
 */
class QaReplyPolicyTest extends TestCase
{
    use RefreshDatabase;

    private QaReplyPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new QaReplyPolicy(new QaThreadPolicy);
    }

    private function thread(string $certificationState = 'published'): QaThread
    {
        $certification = Certification::factory()->{$certificationState}()->create();

        return QaThread::factory()->forCertification($certification)->create();
    }

    private function assignCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_students_and_assigned_coaches_can_reply(): void
    {
        // 回答できる範囲はスレッドの閲覧可否に従う（QaThreadPolicy::view へ委譲）
        $thread = $this->thread();
        $student = User::factory()->student()->create();
        $assignedCoach = User::factory()->coach()->create();
        $unassignedCoach = User::factory()->coach()->create();
        $this->assignCoach($thread->certification, $assignedCoach);

        $this->assertTrue($this->policy->create($student, $thread));
        $this->assertTrue($this->policy->create($assignedCoach, $thread->fresh()));
        $this->assertFalse($this->policy->create($unassignedCoach, $thread));
    }

    public function test_admin_cannot_reply(): void
    {
        // 原典「受講生・コーチによる回答の投稿(管理者は回答できない)」
        $thread = $this->thread();

        $this->assertFalse($this->policy->create(User::factory()->admin()->create(), $thread));
    }

    public function test_nobody_can_reply_to_thread_of_unpublished_certification(): void
    {
        $thread = $this->thread('archived');
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($thread->certification, $coach);

        $this->assertFalse($this->policy->create($student, $thread));
        $this->assertFalse($this->policy->create($coach, $thread->fresh()));
    }

    public function test_update_is_author_only_including_admin_denied(): void
    {
        // 管理者は内容の編集ができない（モデレーションは削除のみ）
        $thread = $this->thread();
        $author = User::factory()->student()->create();
        $reply = QaReply::factory()->forThread($thread)->forUser($author)->create();
        $otherStudent = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();

        $this->assertTrue($this->policy->update($author, $reply));
        $this->assertFalse($this->policy->update($otherStudent, $reply));
        $this->assertFalse($this->policy->update($admin, $reply));
    }

    public function test_delete_is_author_or_admin_without_any_condition(): void
    {
        // decisions #39「投稿者本人のみ・条件なし」+ 管理者のモデレーション削除（支給 Blade が前提にしている）
        $thread = $this->thread();
        $author = User::factory()->student()->create();
        $reply = QaReply::factory()->forThread($thread)->forUser($author)->create();
        $otherStudent = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();

        $this->assertTrue($this->policy->delete($author, $reply));
        $this->assertTrue($this->policy->delete($admin, $reply));
        $this->assertFalse($this->policy->delete($otherStudent, $reply));
    }

    public function test_admin_can_delete_reply_on_unpublished_certification_thread(): void
    {
        // モデレーション削除は公開状態の制限を受けない
        $thread = $this->thread('archived');
        $reply = QaReply::factory()->forThread($thread)->create();

        $this->assertTrue($this->policy->delete(User::factory()->admin()->create(), $reply));
    }
}
