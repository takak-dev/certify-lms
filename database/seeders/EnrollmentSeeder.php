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
use App\Models\EnrollmentStatusLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

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
     * 固定 student に published 資格 4 件を learning で登録し、1 件目に個人目標 + 各件にコーチメモを添える。
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
    }
}
