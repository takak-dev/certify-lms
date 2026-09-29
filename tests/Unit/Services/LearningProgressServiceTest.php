<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Services\LearningProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LearningProgressService の振る舞いを検証する(T-A-03 の要件「集約した Service に対し、
 * 振る舞いを検証できるテストを用意する」)。
 *
 * 4 画面に出る数値そのものは tests/Feature/UseCases/Learning/ProgressFigureCharacterizationTest.php が
 * 固定している。こちらはそれと重ならないよう、**Service が入口の手前で守っている約束**を狙う:
 *   - Section が 1 件も無い Chapter / Part は「完了」にならない
 *   - 複数件の集計は受講登録の数によらず SQL 1 本(N+1 を起こさない)
 *   - 複数件の集計は 1 件ずつの summarize() と同じ値になる
 *   - 空の入力には空配列を返す
 */
class LearningProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    private LearningProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // コンテナ経由で取り出す(Action から使われるときと同じ作り方)
        $this->service = app(LearningProgressService::class);
    }

    public function test_summarize_does_not_count_chapter_or_part_without_published_sections_as_completed(): void
    {
        // Arrange: 公開 Part の中に、公開 Chapter が 2 つ。
        //   - ChA: 公開 Section 1 件を読了 → 完了
        //   - ChB: Section は下書きだけ(公開 Section 0 件)→ 読了していても「完了」にはならない
        // 特性化テストの資格には「公開 Section 0 件の公開 Chapter」が無いので、ここで補う。
        $enrollment = Enrollment::factory()->create();
        $part = Part::factory()->published()->forCertification($enrollment->certification)->create();
        $chA = Chapter::factory()->published()->forPart($part)->create();
        $chB = Chapter::factory()->published()->forPart($part)->create();
        $readable = Section::factory()->published()->forChapter($chA)->create();
        $draftOnly = Section::factory()->draft()->forChapter($chB)->create();
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($readable)->create();
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($draftOnly)->create();

        // Act
        $summary = $this->service->summarize($enrollment);

        // Assert: ChB は総数には入る(公開 Chapter だから)が、完了には数えない
        $this->assertSame(2, $summary->chaptersTotal);
        $this->assertSame(1, $summary->chaptersCompleted);
        $this->assertSame(0.5, $summary->chapterCompletionRatio);
        // Part も、配下の公開 Section(1 件)は全部読んでいるので完了
        //   → ChB が空でも Part の完了は妨げない(Part は Section 単位で判定するため)
        $this->assertSame(1, $summary->partsCompleted);
        // Section は公開の 1 件だけが分母・分子に入り 100%
        $this->assertSame(1.0, $summary->sectionCompletionRatio);
    }

    public function test_section_completion_ratios_match_summarize_for_each_enrollment(): void
    {
        // Arrange: 資格の違う 3 件の受講登録(読了 2/3・0/2・教材なし)
        [$e1, $e2, $e3] = $this->makeThreeEnrollments();

        // Act: まとめて集計
        $ratios = $this->service->sectionCompletionRatios(collect([$e1, $e2, $e3]));

        // Assert: どの受講登録も、1 件ずつの summarize() と同じ値
        //   (ダッシュボードのカードと学習画面の % が食い違わないことの保証)
        foreach ([$e1, $e2, $e3] as $enrollment) {
            $this->assertSame(
                $this->service->summarize($enrollment)->sectionCompletionRatio,
                $ratios[$enrollment->id],
            );
        }
        // 値そのものも確認しておく(上の比較だけだと、両方が同じように壊れたとき気づけない)
        $this->assertSame([$e1->id => 0.6667, $e2->id => 0.0, $e3->id => 0.0], $ratios);
    }

    public function test_section_completion_ratios_issue_a_single_query_regardless_of_count(): void
    {
        // Arrange
        $enrollments = collect($this->makeThreeEnrollments());

        // Act: 実行された SQL を記録しながら集計する
        DB::enableQueryLog();
        $this->service->sectionCompletionRatios($enrollments);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Assert: 3 件でも SQL は 1 本(1 件ずつ集計する形に書き換えると 3 本以上になって落ちる)
        $this->assertCount(1, $queries);
    }

    public function test_empty_inputs_return_empty_arrays_without_querying(): void
    {
        // Arrange: Part 画面用のメソッドには受講登録が要るので 1 件だけ作る
        $enrollment = Enrollment::factory()->create();

        // Act
        DB::enableQueryLog();
        $ratios = $this->service->sectionCompletionRatios(collect());
        $counts = $this->service->completedSectionCountsByChapter($enrollment, collect());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Assert: どちらも空配列で、DB にも問い合わせない
        $this->assertSame([], $ratios);
        $this->assertSame([], $counts);
        $this->assertCount(0, $queries);
    }

    public function test_completed_section_counts_by_chapter_counts_only_own_published_reads(): void
    {
        // Arrange: 1 つの Chapter に公開 2 件 + 下書き 1 件。
        //   自分は公開 1 件と下書き 1 件を読了、他人は公開のもう 1 件を読了。
        $enrollment = Enrollment::factory()->create();
        $other = Enrollment::factory()->for($enrollment->certification)->create();
        $part = Part::factory()->published()->forCertification($enrollment->certification)->create();
        $chapter = Chapter::factory()->published()->forPart($part)->create();
        $unreadChapter = Chapter::factory()->published()->forPart($part)->create();
        $mine = Section::factory()->published()->forChapter($chapter)->create();
        $others = Section::factory()->published()->forChapter($chapter)->create();
        $draft = Section::factory()->draft()->forChapter($chapter)->create();
        Section::factory()->published()->forChapter($unreadChapter)->create();
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($mine)->create();
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($draft)->create();
        SectionProgress::factory()->forEnrollment($other)->forSection($others)->create();

        // Act
        $counts = $this->service->completedSectionCountsByChapter(
            $enrollment,
            collect([$chapter->id, $unreadChapter->id]),
        );

        // Assert: 自分の公開 Section の読了 1 件だけ。未読の Chapter はキーごと現れない
        $this->assertSame([$chapter->id => 1], $counts);
    }

    /**
     * 資格の違う受講登録を 3 件作る。
     *   e1: 公開 Section 3 件中 2 件を読了(0.6667)
     *   e2: 公開 Section 2 件、読了なし(0.0)
     *   e3: 教材なし(0.0)
     *
     * @return array{Enrollment, Enrollment, Enrollment}
     */
    private function makeThreeEnrollments(): array
    {
        $e1 = Enrollment::factory()->create();
        $sections = $this->makePublishedSections($e1->certification, 3);
        SectionProgress::factory()->forEnrollment($e1)->forSection($sections[0])->create();
        SectionProgress::factory()->forEnrollment($e1)->forSection($sections[1])->create();

        $e2 = Enrollment::factory()->create();
        $this->makePublishedSections($e2->certification, 2);

        $e3 = Enrollment::factory()->create();

        return [$e1, $e2, $e3];
    }

    /**
     * 資格の下に「公開 Part → 公開 Chapter → 公開 Section × $count」を作る。
     *
     * @return list<Section>
     */
    private function makePublishedSections(Certification $certification, int $count): array
    {
        $part = Part::factory()->published()->forCertification($certification)->create();
        $chapter = Chapter::factory()->published()->forPart($part)->create();

        return Section::factory()->published()->forChapter($chapter)->count($count)->create()->all();
    }
}
