<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExamSession;

use App\Enums\MockExamSessionStatus;
use App\Enums\TermType;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamQuestion;
use App\Models\MockExamSession;
use App\Models\User;
use App\UseCases\Enrollment\DestroyAction;
use Database\Factories\MockExamSessionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講生が受講解除したあとの、模試の扱いを確かめる(decisions #285)。
 *
 * 受講解除(Enrollment\DestroyAction)は受講登録を論理削除するだけで、受験セッションは残る。
 * - 画面: MockExamSession::enrollment() が解除済みの受講登録を引けないと、結果画面と管理者・コーチの受験詳細が
 *   合格見込みの判定(WeaknessAnalysisService::getPassProbabilityBand(Enrollment))に null を渡して 500 になっていた
 * - 続き: 解除時に未開始・受験中の受験をキャンセル済みにする。採点済みの結果は残り、見返せる。
 *   解除より前から残っている受験は、開始・解答・提出の各処理が受講解除を見て 409 で止める
 */
class UnenrolledEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_open_graded_result_after_unenrolling(): void
    {
        // Arrange: 受講中に模試を受けて採点済みになった受講生が、その資格を受講解除する。
        //   受講解除した後に過去の結果を見返すのは、ふつうに起こる操作。
        ['student' => $student, 'enrollment' => $enrollment, 'mockExam' => $mockExam] = $this->enrollmentWithMockExam();
        $session = $this->sessionFactory($student, $enrollment, $mockExam)->graded()->create();
        app(DestroyAction::class)($enrollment);

        // Act: 受講生が結果画面を開く。
        $response = $this->actingAs($student)->get(route('mock-exam-sessions.show', $session));

        // Assert: 500 にならず開け、模試の名前が出る。採点済みはキャンセルされず、結果として残る。
        $response->assertOk();
        $response->assertSee('解除テスト用の模試');
        $this->assertSame(MockExamSessionStatus::Graded, $session->fresh()->status);
    }

    public function test_student_session_history_opens_after_unenrolling(): void
    {
        // Arrange: 同上。
        //   ⚠️ この一覧は受講登録を読まない(MockExamSession\IndexAction は mockExam.certification だけを先読みする)ので、
        //   修正前から開けていた。再現のためではなく、受講解除した受講生の履歴が今後も開けることの見張りとして置く。
        ['student' => $student, 'enrollment' => $enrollment, 'mockExam' => $mockExam] = $this->enrollmentWithMockExam();
        $this->sessionFactory($student, $enrollment, $mockExam)->graded()->create();
        app(DestroyAction::class)($enrollment);

        // Act: 受講生が受験履歴の一覧を開く。
        $response = $this->actingAs($student)->get(route('mock-exam-sessions.index'));

        // Assert: 開ける。
        $response->assertOk();
    }

    public function test_admin_can_open_session_monitor_after_student_unenrolls(): void
    {
        // Arrange: 同上。管理者は受講生の受験を詳細画面で見る(合格見込みの判定も出す)。
        ['student' => $student, 'enrollment' => $enrollment, 'mockExam' => $mockExam] = $this->enrollmentWithMockExam();
        $session = $this->sessionFactory($student, $enrollment, $mockExam)->graded()->create();
        app(DestroyAction::class)($enrollment);
        $admin = User::factory()->admin()->create();

        // Act: 管理者が受験セッションの詳細を開く。
        $response = $this->actingAs($admin)->get(route('admin.mock-exam-sessions.show', $session));

        // Assert: 500 にならず開ける。
        $response->assertOk();
    }

    public function test_unfinished_sessions_are_canceled_when_student_unenrolls(): void
    {
        // Arrange: 未開始・受験中・採点済みの受験を 1 件ずつ持つ受講登録。
        ['student' => $student, 'enrollment' => $enrollment, 'mockExam' => $mockExam] = $this->enrollmentWithMockExam();
        $notStarted = $this->sessionFactory($student, $enrollment, $mockExam)->notStarted()->create();
        $inProgress = $this->sessionFactory($student, $enrollment, $mockExam)->inProgress()->create();
        $graded = $this->sessionFactory($student, $enrollment, $mockExam)->graded()->create();

        // Act: 受講生本人が受講解除する。
        app(DestroyAction::class)($enrollment);

        // Assert: 未開始と受験中はキャンセル済みになり、採点済みは結果として残る。
        $this->assertSame(MockExamSessionStatus::Canceled, $notStarted->fresh()->status);
        $this->assertNotNull($notStarted->fresh()->canceled_at);
        $this->assertSame(MockExamSessionStatus::Canceled, $inProgress->fresh()->status);
        $this->assertSame(MockExamSessionStatus::Graded, $graded->fresh()->status);
    }

    public function test_student_cannot_continue_in_progress_session_after_unenrolling(): void
    {
        // Arrange: 受験中の模試がある受講登録を、受講生本人が受講解除する(解除でキャンセル済みになる)。
        //   問題を 1 問用意し、受講解除さえしていなければ解答・提出できる状態にしておく。
        ['student' => $student, 'enrollment' => $enrollment, 'mockExam' => $mockExam] = $this->enrollmentWithMockExam();
        $question = MockExamQuestion::factory()->forMockExam($mockExam)->withOptions()->create();
        $session = $this->sessionFactory($student, $enrollment, $mockExam)->inProgress()->create([
            'generated_question_ids' => [$question->id],
            'total_questions' => 1,
        ]);
        app(DestroyAction::class)($enrollment);

        // Act: 受験画面を開き、解答の保存と提出を URL から直接試みる。
        $show = $this->actingAs($student)->get(route('mock-exam-sessions.show', $session));
        $this->actingAs($student)->patchJson(route('mock-exam-sessions.answers.update', $session), [
            'mock_exam_question_id' => $question->id,
            'selected_option_id' => $question->options()->first()->id,
        ]);
        $this->actingAs($student)->post(route('mock-exam-sessions.submit', $session));

        // Assert: 受験画面ではなくキャンセル済みの画面が出て、解答は保存されず、採点もされない。
        $show->assertOk();
        $show->assertViewIs('mock-exam-session.canceled');
        $fresh = $session->fresh();
        $this->assertSame(MockExamSessionStatus::Canceled, $fresh->status);
        $this->assertNull($fresh->graded_at);
        $this->assertDatabaseMissing('mock_exam_answers', ['mock_exam_session_id' => $session->id]);
    }

    public function test_current_term_returns_to_basic_when_in_progress_session_is_canceled(): void
    {
        // Arrange: 受験中の模試が 1 件だけある受講登録(実践ターム)。
        //   ほかに採点済みなどが無いので、キャンセルされると「実践ターム」の根拠が無くなる。
        ['student' => $student, 'enrollment' => $enrollment, 'mockExam' => $mockExam] = $this->enrollmentWithMockExam();
        $enrollment->update(['current_term' => TermType::MockPractice->value]);
        $this->sessionFactory($student, $enrollment, $mockExam)->inProgress()->create();

        // Act: 受講生本人が受講解除する(受験中の模試がキャンセル済みになる)。
        app(DestroyAction::class)($enrollment);

        // Assert: 学習タームが基礎タームに再判定される(模試の状態を変えたら再判定する TermJudgementService の契約)。
        $this->assertSame(TermType::BasicLearning, Enrollment::withTrashed()->find($enrollment->id)->current_term);
    }

    public function test_sessions_left_from_before_unenrolling_cannot_be_continued(): void
    {
        // Arrange: 受講解除の処理を通さずに受講登録だけ論理削除し、未開始・受験中の受験が残った状態を作る。
        //   この変更より前に解除された受講登録(解除時のキャンセルが無かった)や、解除と受験の作成が同時に走った場合を表す。
        ['student' => $student, 'enrollment' => $enrollment, 'mockExam' => $mockExam] = $this->enrollmentWithMockExam();
        $question = MockExamQuestion::factory()->forMockExam($mockExam)->withOptions()->create();
        $notStarted = $this->sessionFactory($student, $enrollment, $mockExam)->notStarted()->create([
            'generated_question_ids' => [$question->id],
            'total_questions' => 1,
        ]);
        $inProgress = $this->sessionFactory($student, $enrollment, $mockExam)->inProgress()->create([
            'generated_question_ids' => [$question->id],
            'total_questions' => 1,
        ]);
        $enrollment->delete();

        // Act: 開始・解答の保存・提出を URL から直接試みる。
        $start = $this->actingAs($student)->postJson(route('mock-exam-sessions.start', $notStarted));
        $answer = $this->actingAs($student)->patchJson(route('mock-exam-sessions.answers.update', $inProgress), [
            'mock_exam_question_id' => $question->id,
            'selected_option_id' => $question->options()->first()->id,
        ]);
        $submit = $this->actingAs($student)->postJson(route('mock-exam-sessions.submit', $inProgress));

        // Assert: 3 つとも 409 で拒否され、状態は変わらず、解答も保存されない。
        $start->assertStatus(409);
        $answer->assertStatus(409);
        $submit->assertStatus(409);
        $this->assertSame(MockExamSessionStatus::NotStarted, $notStarted->fresh()->status);
        $this->assertSame(MockExamSessionStatus::InProgress, $inProgress->fresh()->status);
        $this->assertDatabaseMissing('mock_exam_answers', ['mock_exam_session_id' => $inProgress->id]);
    }

    /**
     * 受講中の受講登録と、その資格の公開済みの模試を作る。作り方は MockExamSession/LifecycleTest と同じ。
     *
     * @return array{student: User, enrollment: Enrollment, mockExam: MockExam}
     */
    private function enrollmentWithMockExam(): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $mockExam = MockExam::factory()->forCertification($certification)->published()->create(['title' => '解除テスト用の模試']);

        return ['student' => $student, 'enrollment' => $enrollment, 'mockExam' => $mockExam];
    }

    /**
     * 受講生・受講登録・模試を指定した受験セッションの factory。状態(notStarted / inProgress / graded)は呼ぶ側で足す。
     */
    private function sessionFactory(User $student, Enrollment $enrollment, MockExam $mockExam): MockExamSessionFactory
    {
        return MockExamSession::factory()->forUser($student)->forEnrollment($enrollment)->forMockExam($mockExam);
    }
}
