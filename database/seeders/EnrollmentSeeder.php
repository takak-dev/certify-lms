<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CertificationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\TermType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certificate;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\EnrollmentNote;
use App\Models\EnrollmentStatusLog;
use App\Models\User;
use App\Services\CertificatePdfService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * 開発用 受講登録シーダー。
 *
 * **設計思想(Seeder 業界標準: 状態網羅 + 固定アカウント、派生・運用系)**:
 *
 * 1. **固定アカウント**(deterministic): 動作確認・スクショ撮影で安定して参照できる「決まったユーザー」の受講登録を生成する。
 *    - `student@certify-lms.test` を CertificationSeeder 投入の published 資格 4 件に learning で登録(ダッシュボードの合格可能性バンド safe / warning / danger / データ不足 を 1 画面で網羅するため)
 *    - 1 件目に個人目標を 4 件追加(目標 CRUD・達成マーク UI の即時確認用)。
 *      達成済 / 未達成 / 期日超過 / 期日なし を 1 件ずつにして、一覧の並び順(decisions #43)と
 *      ダッシュボードの残日数ピル(dashboard/_partials/student/goal-timeline.blade.php:29-40)を
 *      1 画面で確認できるようにする
 *    - coach@(`coach1`) / coach2@ / admin@ が固定 student の Enrollment にメモを残す(他コーチ越境拒否シナリオ用)
 *
 * 2. **状態網羅 demo データ**(Factory + state + count): 一覧 / フィルタ / 状態遷移ボタン / 認可境界が各 status で動くことを実機確認する。
 *    - learning(基礎ターム)/ learning(実践ターム)/ passed / failed / learning(試験日未設定) の 5 パターンを demo student に循環配分
 *    - passed Enrollment には Certificate(`certificates`)を INSERT し、PDF 実体も生成(修了済み → PDF DL の実機確認用)
 *    - 担当 coach が割り当てられている資格 Enrollment にはコーチメモを 1-2 件散らす(coach 動線の即時確認用)
 *    - 個人目標を 0-2 件散らす(coach / admin / 他受講生から見たときの認可分岐の即時確認用。
 *      0 件のケースを残すのは「この受講生はまだ目標を登録していません」の文面を確認するため)
 *
 * 依存順序: `UserSeeder` → `CertificationSeeder`(担当 coach 割当含む)→ 本 Seeder。
 */
final class EnrollmentSeeder extends Seeder
{
    public function run(): void
    {
        $publishedCertifications = Certification::query()
            ->where('status', CertificationStatus::Published->value)
            ->orderBy('created_at')
            ->get();

        if ($publishedCertifications->isEmpty()) {
            $this->command?->warn('EnrollmentSeeder: 公開済資格がありません。先に CertificationSeeder を実行してください。');

            return;
        }

        $fixedStudent = User::query()->where('email', 'student@certify-lms.test')->first();
        // UserSeeder で投入される in_progress 受講生 demo の件数(8 件)に合わせて取得。
        // 固定 student と面談残数 0 の固定 student は別ハンドリングするので whereNotIn で除外し、残り 8 件を循環パターンに割当てる。
        $demoStudents = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->whereNotIn('email', ['student@certify-lms.test', 'student-noquota@certify-lms.test'])
            ->limit(8)
            ->get();

        if ($fixedStudent === null && $demoStudents->isEmpty()) {
            $this->command?->warn('EnrollmentSeeder: 受講生が存在しません。先に UserSeeder を実行してください。');

            return;
        }

        $admin = User::query()->where('role', UserRole::Admin->value)->orderBy('created_at')->first();

        if ($fixedStudent !== null) {
            $this->enrollFixedStudent($fixedStudent, $publishedCertifications, $admin);
        }

        $this->enrollDemoStudents($demoStudents, $publishedCertifications, $admin);
        $this->enrollNoQuotaStudent($publishedCertifications);
    }

    /**
     * 面談残数 0 の受講生を公開資格 1 件に learning で登録する。
     *
     * 面談予約画面は学習中の受講登録を前提とするため、残数 0 での予約拒否を実機確認できるよう
     * 受講登録を 1 件用意する(面談回数の消化は MentoringSeeder が行う)。
     *
     * @param Collection<int, Certification> $publishedCerts
     */
    private function enrollNoQuotaStudent($publishedCerts): void
    {
        $student = User::query()->where('email', 'student-noquota@certify-lms.test')->first();
        $certification = $publishedCerts->first();

        if ($student === null || $certification === null) {
            return;
        }

        $enrollment = Enrollment::firstOrCreate(
            [
                'user_id' => $student->id,
                'certification_id' => $certification->id,
            ],
            [
                'status' => EnrollmentStatus::Learning->value,
                'current_term' => TermType::BasicLearning->value,
                'exam_date' => now()->addMonths(2)->toDateString(),
                'passed_at' => null,
            ],
        );

        EnrollmentStatusLog::firstOrCreate(
            ['enrollment_id' => $enrollment->id, 'to_status' => EnrollmentStatus::Learning->value],
            [
                'from_status' => null,
                'changed_by_user_id' => $student->id,
                'changed_at' => now()->subDays(20),
                'changed_reason' => '新規登録',
            ],
        );
    }

    /**
     * 固定 student に published 資格 4 件を learning で登録し、1 件目に個人目標、4 件のうち 3 件にコーチメモを添える。
     *
     * ⚠️ 支給時の docblock は「各件にコーチメモを添える」だったが、S-B-07 の実装時に 3 件へ変更した。
     *    メモを 1 件も置かない受講登録を 1 つ残すのは、0 件メッセージ「まだメモがありません。」を
     *    実機で確認するため。demo 受講生側では作れない——published 資格 5 件すべてに担当コーチが
     *    割り当てられており(CertificationSeeder)、seedDemoNotes が必ず 1 件以上置くため。
     *    1 件目にだけ個人目標を置いているのと同じ考え方。
     *
     * 4 件にするのは、ダッシュボードの合格可能性バンド(safe / warning / danger / データ不足)を 1 画面で
     * 網羅させるため(各 Enrollment の模試スコアは MockExamSeeder が帯ごとに作り分ける)。
     *
     * @param Collection<int, Certification> $publishedCerts
     */
    private function enrollFixedStudent(User $student, $publishedCerts, ?User $admin): void
    {
        $targets = $publishedCerts->take(4);

        foreach ($targets as $index => $certification) {
            $enrollment = Enrollment::firstOrCreate(
                [
                    'user_id' => $student->id,
                    'certification_id' => $certification->id,
                ],
                [
                    'status' => EnrollmentStatus::Learning->value,
                    'current_term' => TermType::BasicLearning->value,
                    'exam_date' => now()->addMonths(2 + $index)->toDateString(),
                    'passed_at' => null,
                ],
            );

            EnrollmentStatusLog::firstOrCreate(
                ['enrollment_id' => $enrollment->id, 'to_status' => EnrollmentStatus::Learning->value],
                [
                    'from_status' => null,
                    'changed_by_user_id' => $student->id,
                    'changed_at' => now()->subDays(30 - $index * 10),
                    'changed_reason' => '新規登録',
                ],
            );

            // 1 件目の受講登録にだけ個人目標を置く(S-B-05)。
            // 全件に置くと、目標が 1 件も無い状態の見え方(0 件メッセージ)を確認できなくなる
            if ($index === 0) {
                $this->seedFixedStudentGoals($enrollment);
            }

            $this->seedFixedStudentNotes($enrollment, $index, $admin);
        }
    }

    /**
     * 固定 student の受講登録にコーチメモを投入する(S-B-07)。
     *
     * 本 Seeder の docblock が Initial commit の時点で
     * 「coach@(`coach1`) / coach2@ / admin@ が固定 student の Enrollment にメモを残す
     * (他コーチ越境拒否シナリオ用)」と指定していた分の実装。
     *
     * 資格ごとの担当は CertificationSeeder が決めている(published を created_at 順に並べたとき
     * 0,1,2 が coach1、2,3,4 が coach2。index 2 だけ両方が担当する複数指導者シナリオ)。
     * ⚠️ この対応は CertificationSeeder の投入順が前提。資格名ではなく index で書くのは、
     *    本 Seeder 自身が published 資格を orderBy('created_at') で並べて take(4) しており(run() の冒頭)、
     *    固定 student の個人目標も $index === 0 に依存しているため——既存の前提に揃えた。
     *    ⚠️ timestamps は秒精度なので created_at が同値になりうる。その場合この並びは崩れる
     *    (原典が要求する「担当外資格の受講登録にもメモがある」が静かに満たされなくなる)。
     *    Seeder を流したら実物を確認すること。
     * それに合わせて、1 画面ずつ違う認可パターンが見えるように置き分ける。
     *
     * ⚠️ 表の「N 件目」は 1 から数える(このファイルの他の docblock と揃えた)。括弧内は $index の値。
     *
     * | 受講登録    | 担当       | 置くメモ            | 何が確認できるか                         |
     * |------------|-----------|--------------------|----------------------------------------|
     * | 1 件目 (0) | coach1    | coach1 + admin     | 自分のメモは操作可 / 管理者のメモは不可    |
     * | 2 件目 (1) | coach1    | (置かない)          | 0 件メッセージ「まだメモがありません。」   |
     * | 3 件目 (2) | coach1+2  | coach1 + coach2    | 他コーチのメモは読めるがボタンが出ない     |
     * | 4 件目 (3) | coach2    | coach2             | coach1 は画面ごと 403(担当外拒否)        |
     *
     * どの受講登録も受講生本人から見るとメモのカードごと現れない(原典)。
     *
     * ⚠️ firstOrCreate は使えない。author_id を $fillable に入れていないため、
     *    属性配列に混ぜても捨てられる(フォーム経由で作成者を詐称されないようにするための設計)。
     *    本文をキーに存在を確かめてから明示代入する。手本: EnrollmentNote\StoreAction。
     */
    private function seedFixedStudentNotes(Enrollment $enrollment, int $index, ?User $admin): void
    {
        $coach1 = User::query()->where('email', 'coach@certify-lms.test')->first();
        $coach2 = User::query()->where('email', 'coach2@certify-lms.test')->first();

        /** @var list<array{0: ?User, 1: string}> $rows */
        $rows = match ($index) {
            0 => [
                [$coach1, '基礎ターム中盤まで順調に進んでいます。演習の提出も滞りありません。'],
                [$admin, '運営より: 面談回数の残数が少なくなっています。追加購入の案内を送りました。'],
            ],
            2 => [
                [$coach1, 'リスニングの伸びが鈍っています。次回面談で学習時間の配分を見直します。'],
                [$coach2, 'ビジネス文書の設問でつまずきがちです。頻出表現の一覧を共有しました。'],
            ],
            3 => [
                [$coach2, '仕訳の手順は定着してきました。次は決算整理に進んでもらいます。'],
            ],
            default => [],
        };

        foreach ($rows as [$author, $body]) {
            if ($author === null) {
                continue;
            }

            // 本文をキーに冪等化する(Seeder を複数回流しても増えない)
            if ($enrollment->notes()->where('body', $body)->exists()) {
                continue;
            }

            $note = new EnrollmentNote(['body' => $body]);
            $note->enrollment_id = $enrollment->id;
            $note->author_id = $author->id;
            $note->save();
        }
    }

    /**
     * 固定 student の個人目標を投入する(S-B-05)。
     *
     * 達成済 / 未達成 / 期日超過 / 期日なし を 1 件ずつ置く。これで受講登録詳細では
     * 取り消し線とチェックアイコンの視覚区別・達成マーク / 解除・編集 / 削除が、
     * ダッシュボードでは残日数ピル(あと N 日 / 本日まで / N 日超過)が一度に確認できる。
     *
     * タイトルをキーに firstOrCreate するので、Seeder を複数回流しても増えない。
     * ⚠️ achieved_at は $fillable に入れていない(フォーム経由の書き込みを禁じている)ため、
     *    firstOrCreate の属性では入らない。MarkAchievedAction と同じく forceFill で入れる。
     */
    private function seedFixedStudentGoals(Enrollment $enrollment): void
    {
        $rows = [
            [
                'title' => '過去問を 5 年分解き終える',
                'target_date' => now()->addMonth()->toDateString(),
                'description' => '直近 5 年分を 1 年ずつ、時間を計って解く。',
                'achieved_at' => null,
            ],
            [
                'title' => '参考書を 1 周読み切る',
                'target_date' => now()->subDays(3)->toDateString(),   // 期日を過ぎた未達成
                'description' => '分からない箇所には付箋を貼って後で戻る。',
                'achieved_at' => null,
            ],
            [
                'title' => '学習の習慣を作る',
                'target_date' => null,                                 // 期日なし(一覧では末尾)
                'description' => null,
                'achieved_at' => null,
            ],
            [
                'title' => '出題範囲を一通り把握する',
                'target_date' => now()->subWeeks(2)->toDateString(),
                'description' => '公式のシラバスを読み、章ごとの分量を掴む。',
                'achieved_at' => now()->subWeeks(2),                   // 達成済(一覧では最後)
            ],
        ];

        foreach ($rows as $row) {
            // goals() 経由なので enrollment_id はリレーションが入れてくれる
            $goal = $enrollment->goals()->firstOrCreate(
                ['title' => $row['title']],
                [
                    'target_date' => $row['target_date'],
                    'description' => $row['description'],
                ],
            );

            if ($row['achieved_at'] !== null && ! $goal->isAchieved()) {
                // 作成日時も達成日時より前にずらす。既定では created_at が now() になるため、
                // そのままだと「立てる前に達成した」という本番ではありえない行になる
                $goal->forceFill([
                    'achieved_at' => $row['achieved_at'],
                    'created_at' => $row['achieved_at']->copy()->subWeeks(3),
                    // updated_at も揃える。save() が now() に戻してしまうため
                    // (EnrollmentGoalFactory::achieved() と同じ形)
                    'updated_at' => $row['achieved_at'],
                ])->save();
            }
        }
    }

    /**
     * demo 受講生に対し各 status を網羅した受講登録を投入する(admin の status フィルタ・状態遷移ボタンの実機確認用)。
     *
     * @param Collection<int, User> $demoStudents
     * @param Collection<int, Certification> $publishedCerts
     */
    private function enrollDemoStudents($demoStudents, $publishedCerts, ?User $admin): void
    {
        $patterns = [
            ['state' => 'learning', 'examDays' => 60],
            ['state' => 'learning', 'examDays' => 14, 'mockPractice' => true],
            ['state' => 'passed', 'examDays' => -10],
            ['state' => 'failed', 'examDays' => -3],
            ['state' => 'learning', 'examDays' => null],
        ];

        foreach ($demoStudents as $i => $student) {
            $pattern = $patterns[$i % count($patterns)];
            $certification = $publishedCerts->get($i % $publishedCerts->count());
            if ($certification === null) {
                continue;
            }

            $factory = Enrollment::factory()->for($student)->for($certification);
            $factory = match ($pattern['state']) {
                'learning' => $factory->learning(),
                'passed' => $factory->passed(),
                'failed' => $factory->failed(),
            };
            if (! empty($pattern['mockPractice'])) {
                $factory = $factory->mockPractice();
            }
            if ($pattern['examDays'] === null) {
                $factory = $factory->withoutExamDate();
            }

            $passedAt = $pattern['state'] === 'passed' ? now()->subDays(7) : null;

            $enrollment = $factory->create([
                'exam_date' => $pattern['examDays'] === null
                    ? null
                    : now()->addDays($pattern['examDays'])->toDateString(),
                'passed_at' => $passedAt,
            ]);

            $this->seedStatusLogs($enrollment, $pattern['state'], $student);

            // 個人目標を 0 / 1 / 2 件と循環させて散らす(S-B-05)。
            // 0 件の受講生を必ず残すのは、閲覧者によって文面が変わる 0 件メッセージ
            // (enrollment-goal/_form.blade.php:48)を実機で確認するため
            $this->seedDemoGoals($enrollment, $i % 3);

            // 担当コーチが割り当てられている資格の受講登録にだけコーチメモを散らす(S-B-07)。
            // 本 Seeder の docblock が Initial commit の時点で指定していた分の実装
            $this->seedDemoNotes($enrollment, $certification);

            if ($pattern['state'] === 'passed') {
                $this->issueCertificate($enrollment, $passedAt);
            }
        }
    }

    /**
     * demo 受講生の受講登録に個人目標を散らす(S-B-05)。
     *
     * 件数は 0 / 1 / 2 を循環させる。1 件のときは未達成、2 件のときは達成済を 1 件混ぜて、
     * coach / admin から見たときに「達成状況が分かる一覧が見えるが操作ボタンは出ない」ことを
     * 実機で確認できるようにする。
     */
    private function seedDemoGoals(Enrollment $enrollment, int $count): void
    {
        if ($count === 0) {
            return;
        }

        EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        if ($count >= 2) {
            EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create();
        }
    }

    /**
     * demo 受講生の受講登録にコーチメモを散らす(S-B-07)。
     *
     * 件数は「その資格の担当コーチの人数」に一致させる(1 名なら 1 件、2 名なら 2 件)。
     * 担当が 0 名の資格には置かない——誰も読めないメモになり、置いても画面に出ないため。
     * 結果として本 Seeder の docblock が言う「1-2 件散らす」になる
     * (CertificationSeeder が published 資格に 1 名または 2 名を割り当てているため)。
     *
     * 作成者を担当コーチに限るのは、コーチ一覧から受講生詳細をたどったときに
     * 「自分のメモ(操作可)」と「他コーチのメモ(閲覧のみ)」が両方見えるようにするため。
     */
    private function seedDemoNotes(Enrollment $enrollment, Certification $certification): void
    {
        foreach ($certification->coaches as $coach) {
            EnrollmentNote::factory()
                ->forEnrollment($enrollment)
                ->byAuthor($coach)
                ->create();
        }
    }

    private function seedStatusLogs(Enrollment $enrollment, string $finalState, User $student): void
    {
        EnrollmentStatusLog::factory()->for($enrollment)->create([
            'from_status' => null,
            'to_status' => EnrollmentStatus::Learning->value,
            'changed_by_user_id' => $student->id,
            'changed_at' => $enrollment->created_at,
            'changed_reason' => '新規登録',
        ]);

        if ($finalState === 'passed') {
            EnrollmentStatusLog::factory()->for($enrollment)->create([
                'from_status' => EnrollmentStatus::Learning->value,
                'to_status' => EnrollmentStatus::Passed->value,
                'changed_by_user_id' => $student->id,
                'changed_at' => $enrollment->passed_at ?? now(),
                'changed_reason' => '受講生による修了証受領',
            ]);
        }

        if ($finalState === 'failed') {
            EnrollmentStatusLog::factory()->for($enrollment)->create([
                'from_status' => EnrollmentStatus::Learning->value,
                'to_status' => EnrollmentStatus::Failed->value,
                'changed_by_user_id' => null,
                'changed_at' => now()->subDay(),
                'changed_reason' => '試験日超過による自動失敗',
            ]);
        }
    }

    /**
     * passed Enrollment に対し、修了証(`certificates`)行を INSERT し PDF 実体も生成する。
     *
     * 受講生 enrollments.show の「修了済み → PDF DL リンク」と修了証 DL の実機確認用。
     */
    private function issueCertificate(Enrollment $enrollment, ?Carbon $passedAt): void
    {
        if (Certificate::query()->where('enrollment_id', $enrollment->id)->exists()) {
            return;
        }

        $certificate = Certificate::factory()
            ->forEnrollment($enrollment)
            ->create([
                'issued_at' => $passedAt ?? now(),
            ]);

        // PDF の実体を private disk に書く(S-A-04)。この PHPDoc は支給時点から「PDF 実体も生成する」と
        // 書かれていたが実装が無く、修了証 DL が常に 404 になる状態だった。
        Storage::disk('private')->put(
            $certificate->pdf_path,
            app(CertificatePdfService::class)->render($certificate),
        );
    }
}
