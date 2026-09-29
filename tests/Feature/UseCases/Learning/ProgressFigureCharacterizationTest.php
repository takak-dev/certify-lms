<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Learning;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Plan;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Models\User;
use App\Services\Learning\ProgressSummary;
use App\UseCases\Dashboard\FetchStudentDashboardAction;
use App\UseCases\Learning\ShowEnrollmentAction;
use App\UseCases\Learning\ShowPartAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T-A-03 の特性化テスト(characterization test)。
 *
 * 「正しい振る舞い」ではなく「改修前の振る舞い」を固定する。学習進捗の集計を Service へ
 * 集約する前にこのテストを書いて緑を確認し、集約後も**このファイルを 1 行も変えずに**緑であれば、
 * 4 つの画面に表示される進捗の数値が改修の前後で同一だと示せる(原典の要件「集計結果は完全に同一に保つ」)。
 *
 * そのため、Service ではなく**画面に数値を渡す入口**(Action / Controller)を直接叩く。
 *
 * 用意するデータ(1 つの資格に、集計から外れるべき「引っかけ」を混ぜる):
 *
 * | Part       | Chapter      | Section                | この受講生の読了 | 狙い                                       |
 * |------------|--------------|------------------------|------------------|--------------------------------------------|
 * | P1 公開    | Ch1 公開     | S1 公開 / S2 公開      | 両方             | Chapter の完了                             |
 * |            | Ch2 公開     | S3 公開                | なし(他人が読了)| 他の受講登録の読了を数えない               |
 * |            | ChX 下書き   | S7 公開                | あり             | 親 Chapter が下書きなら数えない            |
 * | P2 公開    | Ch3 公開     | S4 公開 / S5 下書き    | 両方             | 下書き Section は総数にも読了数にも入らない |
 * | P3 下書き  | Ch4 公開     | S6 公開                | あり             | 親 Part が下書きなら数えない(開発データで実在) |
 * | P4 公開    | (なし)       | (なし)                 | -                | Section 0 件の Part は完了にならない        |
 *
 * 期待値: Section 3/4(0.75) / Chapter 2/3(0.6667) / Part 1/3(0.3333) / 全体 0.75
 */
class ProgressFigureCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    /** 上の表のデータを持つ受講登録(検証の主役) */
    private Enrollment $enrollment;

    /** 教材が 1 つも無い資格の受講登録(0 除算の扱いを固定する) */
    private Enrollment $emptyEnrollment;

    private Part $p1;

    private Chapter $ch1;

    protected function setUp(): void
    {
        parent::setUp();

        // ダッシュボードはプラン未設定だと別の表示になるため、既存の
        // FetchStudentDashboardActionTest::makeStudentWithPlan() と同じ作り方で受講生を用意する
        $this->student = User::factory()
            ->student()
            ->inProgress()
            ->withPlan(Plan::factory()->published()->create())
            ->create();

        $certification = Certification::factory()->published()->create();
        $this->enrollment = Enrollment::factory()
            ->for($this->student)
            ->for($certification)
            ->learning()
            ->create();

        // 同じ資格を受講している別の受講生。S3 を読了させ、「他人の読了を数えない」ことを確かめる
        $otherEnrollment = Enrollment::factory()->for($certification)->learning()->create();

        // --- P1 公開 ---
        $this->p1 = Part::factory()->published()->forCertification($certification)->create(['order' => 1]);
        $this->ch1 = Chapter::factory()->published()->forPart($this->p1)->create(['order' => 1]);
        $s1 = Section::factory()->published()->forChapter($this->ch1)->create(['order' => 1]);
        $s2 = Section::factory()->published()->forChapter($this->ch1)->create(['order' => 2]);
        $ch2 = Chapter::factory()->published()->forPart($this->p1)->create(['order' => 2]);
        $s3 = Section::factory()->published()->forChapter($ch2)->create();
        $chX = Chapter::factory()->draft()->forPart($this->p1)->create(['order' => 3]);
        $s7 = Section::factory()->published()->forChapter($chX)->create();

        // --- P2 公開 ---
        $p2 = Part::factory()->published()->forCertification($certification)->create(['order' => 2]);
        $ch3 = Chapter::factory()->published()->forPart($p2)->create();
        $s4 = Section::factory()->published()->forChapter($ch3)->create(['order' => 1]);
        $s5 = Section::factory()->draft()->forChapter($ch3)->create(['order' => 2]);

        // --- P3 下書き(配下の Chapter / Section は公開済みでも、親が下書きなので外れる) ---
        $p3 = Part::factory()->draft()->forCertification($certification)->create(['order' => 3]);
        $ch4 = Chapter::factory()->published()->forPart($p3)->create();
        $s6 = Section::factory()->published()->forChapter($ch4)->create();

        // --- P4 公開・中身なし ---
        Part::factory()->published()->forCertification($certification)->create(['order' => 4]);

        // この受講生の読了: S1 / S2 / S4 は数えられる。S5 / S6 / S7 は親か自分が下書きなので数えられない
        foreach ([$s1, $s2, $s4, $s5, $s6, $s7] as $section) {
            SectionProgress::factory()->forEnrollment($this->enrollment)->forSection($section)->create();
        }
        // 他人の読了: S3。この受講生の集計には入らない
        SectionProgress::factory()->forEnrollment($otherEnrollment)->forSection($s3)->create();

        // 教材ゼロの資格の受講登録(同じ受講生。ダッシュボードに 2 枚目のカードとして並ぶ)
        $this->emptyEnrollment = Enrollment::factory()
            ->for($this->student)
            ->for(Certification::factory()->published()->create())
            ->learning()
            ->create();
    }

    /**
     * 受講生の学習画面(/learning/enrollments/{id})に渡る 4 階層サマリ。
     */
    public function test_learning_enrollment_page_progress_figures(): void
    {
        // Act: 学習画面の Action を呼び、Blade に渡る 'progress' を取り出す
        $progress = app(ShowEnrollmentAction::class)($this->enrollment)['progress'];

        // Assert: 10 項目すべてを固定する(1 項目でも変われば要件違反)
        $this->assertProgressFigures($progress);
    }

    /**
     * 管理者・コーチ向けの受講登録詳細(/enrollments/{id})に渡る 4 階層サマリ。
     * こちらは Controller の private メソッドで集計しているので、HTTP 経由で確かめる。
     */
    public function test_staff_enrollment_detail_page_progress_figures(): void
    {
        // Arrange: 進捗が集計されるのは staff(admin / coach)だけなので管理者で見る
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->get(route('enrollments.show', $this->enrollment));

        // Assert: 画面が出て、ビューに渡った 'progress' が学習画面と同じ数値であること
        $response->assertOk();
        $this->assertProgressFigures($response->viewData('progress'));
    }

    /**
     * 教材が 1 つも無い資格では、総数 0 でも割り算で落ちず、すべて 0 になる。
     */
    public function test_enrollment_without_contents_yields_all_zero(): void
    {
        // Act
        $progress = app(ShowEnrollmentAction::class)($this->emptyEnrollment)['progress'];

        // Assert: 完了率は 0 除算を避けて 0.0(int の 0 ではなく float)
        $this->assertEquals(
            new ProgressSummary(0, 0, 0.0, 0, 0, 0.0, 0, 0, 0.0, 0.0),
            $progress,
        );
        $this->assertSame(0.0, $progress->overallCompletionRatio);
    }

    /**
     * 受講生ダッシュボードのカードに出る完了率(Section 単位)。
     * 複数の受講登録をまとめて集計する別実装なので、2 件並べて確かめる。
     */
    public function test_student_dashboard_card_progress_ratio(): void
    {
        // Act
        $vm = app(FetchStudentDashboardAction::class)($this->student);

        // Assert: 受講登録 ID でカードを引き、それぞれの完了率を固定する
        $ratios = $vm->enrollmentCards->pluck('progressRatio', 'enrollmentId');
        $this->assertSame(0.75, $ratios[$this->enrollment->id], '学習画面の overallCompletionRatio と同じ値');
        $this->assertSame(0.0, $ratios[$this->emptyEnrollment->id], '教材ゼロの資格は 0.0');
    }

    /**
     * Part 画面(/learning/parts/{id})に渡る「Chapter ごとの読了数」。
     */
    public function test_part_page_completed_counts_by_chapter(): void
    {
        // Act: P1 を開く
        $completed = app(ShowPartAction::class)($this->p1, $this->student)['completedByChapter'];

        // Assert: 読了のある Ch1 だけが 2 件で載る。
        //   Ch2 は他人しか読了していないので**キーごと現れない**(0 ではない。Blade 側が ?? 0 で補う)。
        //   ChX は下書きなので画面の Chapter 一覧にも集計にも出ない。
        $this->assertSame([$this->ch1->id => 2], $completed);
    }

    /**
     * 上の表から導いた期待値。学習画面と受講登録詳細の両方で同じものを使う。
     */
    private function assertProgressFigures(ProgressSummary $progress): void
    {
        // Section: S1 S2 S3 S4 の 4 件中、S1 S2 S4 を読了
        $this->assertSame(4, $progress->sectionsTotal);
        $this->assertSame(3, $progress->sectionsCompleted);
        $this->assertSame(0.75, $progress->sectionCompletionRatio);

        // Chapter: Ch1 Ch2 Ch3 の 3 件中、Ch1 Ch3 が完了(比率は小数第 4 位で丸め)
        $this->assertSame(3, $progress->chaptersTotal);
        $this->assertSame(2, $progress->chaptersCompleted);
        $this->assertSame(0.6667, $progress->chapterCompletionRatio);

        // Part: P1 P2 P4 の 3 件中、P2 だけ完了(P1 は Ch2 が未読、P4 は Section が無い)
        $this->assertSame(3, $progress->partsTotal);
        $this->assertSame(1, $progress->partsCompleted);
        $this->assertSame(0.3333, $progress->partCompletionRatio);

        // 全体: Section 単位の比率と同じ値を使う(ProgressSummary のクラスコメントの仕様)
        $this->assertSame(0.75, $progress->overallCompletionRatio);
    }
}
