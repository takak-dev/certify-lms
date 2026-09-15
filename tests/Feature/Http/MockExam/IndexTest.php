<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Models\Certification;
use App\Models\MockExam;
use App\Models\MockExamQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 模試マスタ一覧（GET /admin/mock-exams）の表示内容の検証。
 *
 * 守りたいのは「問題数」列。この列は IndexAction の withCount('mockExamQuestions') が
 * 生やす mock_exam_questions_count 属性だけを頼りにしている
 * （resources/views/mock-exam/management/index.blade.php:91）。
 * withCount を落としても属性が無くなるだけで例外は起きず、Blade の `?? 0` が受け止めて
 * 静かに「0」を表示する。クエリ本数も増えないので MockExamIndexQueryCountTest では
 * 検知できない（T-A-01 で実測済み）。その死角をここで塞ぐ。
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 一覧の各行に、その模試が持つ問題の件数が渡ること。
     *
     * 問題を持つ模試と持たない模試を並べるのは、「常に 0 しか出ない」不具合
     * （T-A-01 で修正）と「正しく 0」を区別するため。前者だけを赤にしたい。
     */
    public function test_index_exposes_question_count_for_each_mock_exam(): void
    {
        // Arrange: 管理者 1 人と、同じ資格配下の模試 2 件（問題 7 件 / 問題なし）
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();

        $withQuestions = MockExam::factory()->forCertification($certification)->create();
        MockExamQuestion::factory()->count(7)->forMockExam($withQuestions)->create();

        $withoutQuestions = MockExam::factory()->forCertification($certification)->create();

        // Act
        $response = $this->actingAs($admin)->get(route('admin.mock-exams.index'));

        // Assert: ビューに渡るページネータから件数を取り出して照合する。
        // 画面の文字列（assertSee）で見ないのは、各行のリンク先 URL に ULID が入っていて
        // 数字がたまたま一致しうるため。渡っている値そのものを見るほうが確実。
        //
        // (int) を挟むのは、withCount の結果に Laravel が型キャストを付けないため。
        // QueriesRelationships::withAggregate が withCasts() を呼ぶのは exists の bool だけで
        // （framework の同ファイル 681 行）、count は DB ドライバが返した型のまま届く。
        // いまの MySQL では int だが、それはドライバの挙動でフレームワークの保証ではない。
        $response->assertOk();
        $response->assertViewHas('mockExams', function ($mockExams) use ($withQuestions, $withoutQuestions): bool {
            $counts = $mockExams->pluck('mock_exam_questions_count', 'id');

            return (int) $counts[$withQuestions->id] === 7
                && (int) $counts[$withoutQuestions->id] === 0;
        });
    }

    /**
     * coach が開いたとき、一覧が担当資格の模試だけに絞られること。
     *
     * この絞り込みは IndexAction の whereHas('certification.coaches', ...) が担っている。
     * 消しても例外は起きず、クエリ本数も減りこそすれ増えないため、
     * MockExamIndexQueryCountTest でも上の問題数テストでも検知できない。
     * T-A-01 で IndexAction のクエリ組み立てに手を入れたので、その保護をここに置く。
     */
    public function test_index_hides_mock_exams_of_unassigned_certifications_from_coach(): void
    {
        // Arrange: 担当資格 1 つと担当外の資格 1 つ。それぞれに模試を 1 件ずつ置く
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();

        $assigned = Certification::factory()->published()->create();
        // 担当の紐付けは中間テーブルへ直接 attach する（手本: tests/Unit/Policies/MockExamPolicyTest.php:43-47）。
        // 主キーが ULID なので id も自分で渡す必要がある
        $assigned->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
        $unassigned = Certification::factory()->published()->create();

        $visible = MockExam::factory()->forCertification($assigned)->create();
        $hidden = MockExam::factory()->forCertification($unassigned)->create();

        // Act
        $response = $this->actingAs($coach)->get(route('admin.mock-exams.index'));

        // Assert: 担当資格の模試だけが並び、担当外は 1 件も混ざらない
        $response->assertOk();
        $response->assertViewHas('mockExams', function ($mockExams) use ($visible, $hidden): bool {
            $ids = $mockExams->pluck('id')->all();

            return $ids === [$visible->id] && ! in_array($hidden->id, $ids, true);
        });
    }
}
