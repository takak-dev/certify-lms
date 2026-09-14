<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\TermType;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Services\TermJudgementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermJudgementServiceTest extends TestCase
{
    use RefreshDatabase;

    private TermJudgementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TermJudgementService::class);
    }

    public function test_returns_basic_learning_when_no_mock_exam_session_exists(): void
    {
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::BasicLearning->value]);

        $term = $this->service->recalculate($enrollment);

        $this->assertSame(TermType::BasicLearning, $term);
    }

    public function test_returns_basic_learning_when_only_not_started_session_exists(): void
    {
        $enrollment = Enrollment::factory()->create();
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'not_started']);

        $term = $this->service->recalculate($enrollment);

        $this->assertSame(TermType::BasicLearning, $term);
    }

    public function test_returns_basic_learning_when_only_canceled_session_exists(): void
    {
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::MockPractice->value]);
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'canceled']);

        $term = $this->service->recalculate($enrollment);

        $this->assertSame(TermType::BasicLearning, $term);
        $this->assertSame(TermType::BasicLearning, $enrollment->refresh()->current_term);
    }

    public function test_returns_mock_practice_when_in_progress_session_exists(): void
    {
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::BasicLearning->value]);
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'in_progress']);

        $term = $this->service->recalculate($enrollment);

        $this->assertSame(TermType::MockPractice, $term);
        $this->assertSame(TermType::MockPractice, $enrollment->refresh()->current_term);
    }

    public function test_returns_mock_practice_when_submitted_session_exists(): void
    {
        $enrollment = Enrollment::factory()->create();
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'submitted']);

        $term = $this->service->recalculate($enrollment);

        $this->assertSame(TermType::MockPractice, $term);
    }

    public function test_returns_mock_practice_when_graded_session_exists(): void
    {
        $enrollment = Enrollment::factory()->create();
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'graded']);

        $term = $this->service->recalculate($enrollment);

        $this->assertSame(TermType::MockPractice, $term);
    }

    /**
     * キャンセル済みと採点完了が同居する場合に、実践タームを維持することを検証する。
     *
     * 「キャンセルを数えない」修正が行き過ぎて「キャンセルが混ざると他も無視する」ことにならないかを押さえる。
     * 既存 7 本はすべてセッション 1 件だけのケースで、判定を `exists()` から `count() === 1` に
     * 書き換えても 7 本とも通ってしまう(実測)。採点完了を 2 件にしているのはそのためで、
     * 1 件だと壊れた実装でも正しい結果が出てしまい検知できない。
     */
    public function test_returns_mock_practice_when_canceled_and_graded_sessions_coexist(): void
    {
        // Arrange: 同じ模試を再受験・キャンセルすると、1 つの模試に複数セッションが並ぶ。
        //          初期データにも「採点完了 2 件 + 受験中 1 件 + キャンセル 1 件」の受講登録がある(MockExamSeeder.php:231-249)。
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::MockPractice->value]);
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'canceled']);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'graded']);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'graded']);

        // Act
        $term = $this->service->recalculate($enrollment);

        // Assert: 採点完了が 1 件でもあれば実践ターム。キャンセルが混ざっていても落ちない。
        $this->assertSame(TermType::MockPractice, $term);
        $this->assertSame(TermType::MockPractice, $enrollment->refresh()->current_term);
    }

    public function test_does_not_update_when_current_term_already_matches(): void
    {
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::BasicLearning->value]);
        $originalUpdatedAt = $enrollment->updated_at;

        sleep(1);
        $this->service->recalculate($enrollment);

        $this->assertEquals($originalUpdatedAt->timestamp, $enrollment->refresh()->updated_at->timestamp);
    }
}
