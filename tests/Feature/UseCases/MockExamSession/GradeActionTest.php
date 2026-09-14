<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MockExamSession;

use App\Enums\MockExamSessionStatus;
use App\Models\MockExam;
use App\Models\MockExamAnswer;
use App\Models\MockExamQuestion;
use App\Models\MockExamSession;
use App\UseCases\MockExamSession\GradeAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradeActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_grades_session_with_mixed_correct_and_wrong_answers(): void
    {
        $mockExam = MockExam::factory()->published()->passingScore(60)->create();
        $questions = collect();
        for ($i = 0; $i < 5; $i++) {
            $questions->push(MockExamQuestion::factory()->forMockExam($mockExam)->withOptions(4, 0)->create(['order' => $i]));
        }

        $session = MockExamSession::factory()
            ->forMockExam($mockExam)
            ->inProgress()
            ->create([
                'generated_question_ids' => $questions->pluck('id')->all(),
                'total_questions' => 5,
                'passing_score_snapshot' => 60,
            ]);

        // 3 問正解(60%) - 合格点ぴったり
        foreach ($questions as $index => $question) {
            $correctOption = $question->options->firstWhere('is_correct', true);
            $wrongOption = $question->options->firstWhere('is_correct', false);
            $selected = $index < 3 ? $correctOption : $wrongOption;

            MockExamAnswer::factory()->create([
                'mock_exam_session_id' => $session->id,
                'mock_exam_question_id' => $question->id,
                'selected_option_id' => $selected->id,
                'selected_option_body' => $selected->body,
                'is_correct' => false,
                'answered_at' => now(),
            ]);
        }

        (app(GradeAction::class))($session);

        $session->refresh();
        $this->assertSame(MockExamSessionStatus::Graded, $session->status);
        $this->assertSame(3, $session->total_correct);
        $this->assertEquals(60.00, (float) $session->score_percentage);
        $this->assertTrue($session->pass);
    }

    public function test_grades_session_below_passing_score_as_fail(): void
    {
        $mockExam = MockExam::factory()->published()->passingScore(70)->create();
        $questions = collect();
        for ($i = 0; $i < 4; $i++) {
            $questions->push(MockExamQuestion::factory()->forMockExam($mockExam)->withOptions(4, 0)->create(['order' => $i]));
        }

        $session = MockExamSession::factory()
            ->forMockExam($mockExam)
            ->inProgress()
            ->create([
                'generated_question_ids' => $questions->pluck('id')->all(),
                'total_questions' => 4,
                'passing_score_snapshot' => 70,
            ]);

        // 2 問正解(50%、合格点 70% 未満)
        foreach ($questions as $index => $question) {
            $correctOption = $question->options->firstWhere('is_correct', true);
            $wrongOption = $question->options->firstWhere('is_correct', false);
            $selected = $index < 2 ? $correctOption : $wrongOption;

            MockExamAnswer::factory()->create([
                'mock_exam_session_id' => $session->id,
                'mock_exam_question_id' => $question->id,
                'selected_option_id' => $selected->id,
                'selected_option_body' => $selected->body,
                'is_correct' => false,
                'answered_at' => now(),
            ]);
        }

        (app(GradeAction::class))($session);

        $session->refresh();
        $this->assertFalse($session->pass);
        $this->assertEquals(50.00, (float) $session->score_percentage);
    }

    public function test_unanswered_questions_count_as_incorrect(): void
    {
        $mockExam = MockExam::factory()->published()->passingScore(50)->create();
        $questions = collect();
        for ($i = 0; $i < 3; $i++) {
            $questions->push(MockExamQuestion::factory()->forMockExam($mockExam)->withOptions(4, 0)->create(['order' => $i]));
        }

        $session = MockExamSession::factory()
            ->forMockExam($mockExam)
            ->inProgress()
            ->create([
                'generated_question_ids' => $questions->pluck('id')->all(),
                'total_questions' => 3,
                'passing_score_snapshot' => 50,
            ]);

        // 1 問のみ正解、残り 2 問は未解答(MockExamAnswer レコードなし)
        $firstQuestion = $questions->first();
        $correctOption = $firstQuestion->options->firstWhere('is_correct', true);
        MockExamAnswer::factory()->create([
            'mock_exam_session_id' => $session->id,
            'mock_exam_question_id' => $firstQuestion->id,
            'selected_option_id' => $correctOption->id,
            'selected_option_body' => $correctOption->body,
            'is_correct' => false,
            'answered_at' => now(),
        ]);

        (app(GradeAction::class))($session);

        $session->refresh();
        $this->assertSame(1, $session->total_correct);
        // 1/3 = 33.33% — 50% 未満で不合格
        $this->assertFalse($session->pass);
    }

    /**
     * 満点(5 問中 5 問正解)のときに得点率が 100.00 になることを検証する。
     *
     * 得点率が 3 桁になる唯一のケース。score_percentage は decimal(5,2) なので 100.00 まで格納できるが、
     * 精度が縮むと満点だけが壊れる。B-A-02 の修正前はここが 1.0 で保存されていた。
     */
    public function test_grades_perfect_score_as_one_hundred_percent(): void
    {
        // Arrange: 合格点 60% / 5 問の模試を用意する。
        //          合格点の値は 100.00 を格納できるかどうかに影響しないため、既存テストと同じ 60 に揃えた。
        $mockExam = MockExam::factory()->published()->passingScore(60)->create();
        $questions = collect();
        for ($i = 0; $i < 5; $i++) {
            $questions->push(MockExamQuestion::factory()->forMockExam($mockExam)->withOptions(4, 0)->create(['order' => $i]));
        }

        $session = MockExamSession::factory()
            ->forMockExam($mockExam)
            ->inProgress()
            ->create([
                'generated_question_ids' => $questions->pluck('id')->all(),
                'total_questions' => 5,
                'passing_score_snapshot' => 60,
            ]);

        // Arrange: 全問に正解の選択肢を選ばせる。
        //          is_correct は false で登録する - 採点前の解答は必ず未確定で、GradeAction が確定させる責務だから。
        foreach ($questions as $question) {
            $correctOption = $question->options->firstWhere('is_correct', true);

            MockExamAnswer::factory()->create([
                'mock_exam_session_id' => $session->id,
                'mock_exam_question_id' => $question->id,
                'selected_option_id' => $correctOption->id,
                'selected_option_body' => $correctOption->body,
                'is_correct' => false,
                'answered_at' => now(),
            ]);
        }

        // Act
        (app(GradeAction::class))($session);

        // Assert: 5/5 = 100.00。小数の 1.0 ではなく百分率で保存され、合格点 60 を上回って合格になる。
        $session->refresh();
        $this->assertSame(5, $session->total_correct);
        $this->assertEquals(100.00, (float) $session->score_percentage);
        $this->assertTrue($session->pass);
    }

    /**
     * 割り切れない得点率が小数第 2 位に丸められることを検証する。
     *
     * 2/3 = 66.666... を選んだのは切り上げ方向の丸めになるため(1/3 の切り捨て方向より検知力が高い)。
     * round() の位置を変えても(`round($a / $b, 2) * 100` = 67)、floor() で切り捨てても(66.66)、66.67 以外の値になってここで落ちる。
     * 合否は assert しない - 66.67 と 66.66 の差は整数の合格点ではどちらに倒しても同じ側に落ちるため、
     * 丸めの検証には無関係だから。
     */
    public function test_rounds_score_percentage_to_two_decimal_places(): void
    {
        // Arrange: 3 問の模試。3 問中 2 問正解で 66.666...% という割り切れない値を作るための問題数。
        $mockExam = MockExam::factory()->published()->passingScore(60)->create();
        $questions = collect();
        for ($i = 0; $i < 3; $i++) {
            $questions->push(MockExamQuestion::factory()->forMockExam($mockExam)->withOptions(4, 0)->create(['order' => $i]));
        }

        $session = MockExamSession::factory()
            ->forMockExam($mockExam)
            ->inProgress()
            ->create([
                'generated_question_ids' => $questions->pluck('id')->all(),
                'total_questions' => 3,
                'passing_score_snapshot' => 60,
            ]);

        // Arrange: 先頭 2 問だけ正解の選択肢を選ばせる(2/3 = 66.666...%)。
        foreach ($questions as $index => $question) {
            $correctOption = $question->options->firstWhere('is_correct', true);
            $wrongOption = $question->options->firstWhere('is_correct', false);
            $selected = $index < 2 ? $correctOption : $wrongOption;

            MockExamAnswer::factory()->create([
                'mock_exam_session_id' => $session->id,
                'mock_exam_question_id' => $question->id,
                'selected_option_id' => $selected->id,
                'selected_option_body' => $selected->body,
                'is_correct' => false,
                'answered_at' => now(),
            ]);
        }

        // Act
        (app(GradeAction::class))($session);

        // Assert: 66.666... が小数第 2 位で切り上げられて 66.67 になる。切り捨て実装なら 66.66 で落ちる。
        $session->refresh();
        $this->assertSame(2, $session->total_correct);
        $this->assertEquals(66.67, (float) $session->score_percentage);
    }
}
