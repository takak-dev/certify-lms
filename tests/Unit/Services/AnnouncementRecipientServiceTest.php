<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\AnnouncementTargetType;
use App\Enums\EnrollmentStatus;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\AnnouncementRecipientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 配信対象を解決するサービスの単体テスト。
 *
 * ⭐ HTTP 経由（StoreTest）でも同じ集合を検査しているが、こちらを別に置く理由は 2 つ。
 * ① このサービスは AnnouncementSeeder からも呼ばれ、そちらの経路は Feature テストを通らない
 * ② 未保存の Announcement を渡せることが呼び出し側の前提になっている
 *    （配信件数を決めるために、保存前に対象を知る必要がある）。その前提をここで固定する
 */
class AnnouncementRecipientServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AnnouncementRecipientService
    {
        return app(AnnouncementRecipientService::class);
    }

    /** 保存していない Announcement を渡しても解決できる（StoreAction がその順序で呼ぶ） */
    public function test_resolves_from_an_unsaved_announcement(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $announcement = new Announcement([
            'title' => 'テスト',
            'body' => 'テスト',
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ]);

        // Act
        $recipients = $this->service()->resolve($announcement);

        // Assert
        $this->assertFalse($announcement->exists, '保存済みの Announcement では前提の検証にならない');
        $this->assertSame([$student->id], $recipients->pluck('id')->all());
    }

    /**
     * 全受講生あては「受講中の受講生」だけ。
     * コーチ・管理者・修了者・退会者は 3 タイプすべてで土台から落ちる。
     */
    public function test_all_students_excludes_everyone_who_is_not_an_in_progress_student(): void
    {
        // Arrange
        $target = User::factory()->student()->inProgress()->create();
        User::factory()->student()->invited()->create();
        User::factory()->student()->graduated()->create();
        User::factory()->student()->withdrawn()->create();
        User::factory()->coach()->inProgress()->create();
        User::factory()->admin()->create();
        User::factory()->student()->inProgress()->create()->delete(); // 退会処理（論理削除）

        // Act
        $recipients = $this->service()->resolve(
            Announcement::factory()->make(['target_type' => AnnouncementTargetType::AllStudents->value])
        );

        // Assert
        $this->assertSame([$target->id], $recipients->pluck('id')->all());
    }

    /**
     * 資格指定は「その資格に受講登録がある受講生」。受講登録の状態では絞らない（decisions #91）。
     * 原典のスコープ外「配信ターゲット内の追加絞り込み（学習進捗フィルタ等）」に触れないため。
     */
    public function test_certification_target_ignores_the_enrollment_status(): void
    {
        // Arrange: 同じ資格に 3 状態で登録している受講生と、別資格の受講生
        $certification = Certification::factory()->create();
        $other = Certification::factory()->create();

        $expected = collect(EnrollmentStatus::cases())
            ->map(fn (EnrollmentStatus $status) => $this->enrolled($certification, $status)->id)
            ->sort()
            ->values()
            ->all();
        $this->enrolled($other, EnrollmentStatus::Learning);

        // Act
        $recipients = $this->service()->resolve(Announcement::factory()->make([
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certification->id,
        ]));

        // Assert
        $this->assertSame($expected, $recipients->pluck('id')->sort()->values()->all());
    }

    /** 論理削除された受講登録は「登録が無い」のと同じ扱い */
    public function test_certification_target_ignores_soft_deleted_enrollments(): void
    {
        // Arrange
        $certification = Certification::factory()->create();
        $student = $this->enrolled($certification, EnrollmentStatus::Learning);
        $student->enrollments()->first()->delete();

        // Act
        $recipients = $this->service()->resolve(Announcement::factory()->make([
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certification->id,
        ]));

        // Assert
        $this->assertTrue($recipients->isEmpty());
    }

    /** ユーザー指定でも土台の絞り込みは効く。配信できない相手を渡すと 0 件になる */
    public function test_user_target_still_passes_through_the_base_scope(): void
    {
        // Arrange
        $graduated = User::factory()->student()->graduated()->create();

        // Act
        $recipients = $this->service()->resolve(Announcement::factory()->make([
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => $graduated->id,
        ]));

        // Assert: FormRequest でも弾いているが、こちらも独立に閉じていることを確かめる
        $this->assertTrue($recipients->isEmpty());
    }

    /** 指定した資格に、指定した状態で受講登録している受講中の受講生を作る */
    private function enrolled(Certification $certification, EnrollmentStatus $status): User
    {
        $student = User::factory()->student()->inProgress()->create();

        Enrollment::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
            'status' => $status->value,
        ]);

        return $student;
    }
}
