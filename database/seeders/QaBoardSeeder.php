<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 開発用 質問掲示板シーダー。
 *
 * **チケット S-B-01「初期データ」の指定に対応する**:
 *
 * 1. **公開済の資格ごとにスレッドを散布**: 未解決 / 解決済を混在させ、資格フィルタと状態フィルタの
 *    双方を実データで確認できるようにする。
 *
 * 2. **回答数と作成日時をばらつかせる**: 一覧カードの状態バッジは「解決済 / 未回答(0 件) / 対応中」の
 *    3 種類あり(`_thread-card.blade.php:8-12`)、回答 0 件のスレッドが無いと「未回答」を確認できない。
 *    作成日時をずらすのは新着順とページネーションの確認用。
 *
 * 3. **固定の受講生を投稿者にしたスレッドを用意**: 自分の質問に対する編集 / 削除 / 解決マークの動線を
 *    ログイン直後に確認できるようにする。
 *
 * **公開停止中の資格にもスレッドを1件作る**: 管理者だけがモデレーション画面で閲覧できることの確認用。
 * 受講生・コーチの一覧には出てはならない(decisions #40 と原典のアクセス制御)。
 *
 * 依存順序: `UserSeeder` → `CertificationSeeder`(担当コーチ割当含む)→ 本 Seeder。
 */
final class QaBoardSeeder extends Seeder
{
    /** 1スレッドあたりの回答数のばらつき(0 件を必ず含める) */
    private const REPLY_COUNTS = [0, 1, 3, 0, 2];

    public function run(): void
    {
        $students = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->get();

        if ($students->isEmpty()) {
            $this->command?->warn('QaBoardSeeder: 受講中の受講生が存在しません。先に UserSeeder を実行してください。');

            return;
        }

        $published = Certification::query()->published()->get();

        if ($published->isEmpty()) {
            $this->command?->warn('QaBoardSeeder: 公開中の資格が存在しません。先に CertificationSeeder を実行してください。');

            return;
        }

        foreach ($published as $index => $certification) {
            $this->seedThreadsFor($certification, $students, $index);
        }

        $this->seedFixedStudentThreads($published->first());
        $this->seedUnpublishedCertificationThread($students->first());
    }

    /**
     * 1資格あたり5スレッド。解決済を混ぜ、回答数と作成日時をばらつかせる。
     *
     * @param Collection<int, User> $students
     */
    private function seedThreadsFor(Certification $certification, Collection $students, int $certIndex): void
    {
        foreach (self::REPLY_COUNTS as $i => $replyCount) {
            // 5件中2件を解決済にする(状態フィルタの両方に結果が出るようにする)
            $isResolved = $i % 3 === 1;

            $factory = QaThread::factory()
                ->forCertification($certification)
                ->forUser($students[($certIndex + $i) % $students->count()]);

            if ($isResolved) {
                $factory = $factory->resolved();
            }

            // 新着順の確認用に作成日時をずらす(古いものほど後ろに並ぶ)
            $createdAt = now()->subDays($certIndex * 5 + $i)->subHours($i * 3);

            $thread = $factory->create([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $this->seedReplies($thread, $replyCount, $students, $createdAt);
        }
    }

    /**
     * 回答を投稿順に作る。受講生とコーチの回答を混在させる(管理者は投稿できない)。
     *
     * @param Collection<int, User> $students
     */
    private function seedReplies(QaThread $thread, int $count, Collection $students, Carbon $threadCreatedAt): void
    {
        for ($i = 0; $i < $count; $i++) {
            $repliedAt = $threadCreatedAt->copy()->addHours($i + 1);

            // 偶数番目はコーチ、奇数番目は他の受講生が回答する。
            // コーチは「その資格の担当コーチ」から選ぶ(担当外のコーチが回答しているデモデータを作らない)
            $coaches = $thread->certification->coaches;
            $factory = QaReply::factory()->forThread($thread);
            $factory = $i % 2 === 0 && $coaches->isNotEmpty()
                ? $factory->forUser($coaches[$i % $coaches->count()])
                : $factory->forUser($students[($i + 1) % $students->count()]);

            $factory->create([
                'created_at' => $repliedAt,
                'updated_at' => $repliedAt,
            ]);
        }
    }

    /**
     * 固定アカウント(student@certify-lms.test)を投稿者にしたスレッド。
     * 未解決と解決済を1件ずつ用意し、ログイン直後に自分の質問の操作を確認できるようにする。
     */
    private function seedFixedStudentThreads(Certification $certification): void
    {
        $student = User::query()->where('email', 'student@certify-lms.test')->first();

        if ($student === null) {
            return;
        }

        $unresolved = QaThread::factory()
            ->forUser($student)
            ->forCertification($certification)
            ->create([
                'title' => '模試の復習の進め方について相談させてください',
                'body' => '模試で間違えた問題の復習に時間がかかりすぎています。優先順位の付け方を教えていただけますか。',
                'created_at' => now()->subHours(6),
                'updated_at' => now()->subHours(6),
            ]);

        // 回答者はその資格の担当コーチから選ぶ（担当外のコーチが回答しているデモデータを作らない）
        $coach = $certification->coaches->first() ?? User::factory()->coach()->create();

        QaReply::factory()->forThread($unresolved)->forUser($coach)->create([
            'body' => '誤答のうち「理解不足」と「読み違い」を分けてみてください。前者だけを復習対象にすると時間が半分になります。',
            'created_at' => now()->subHours(4),
            'updated_at' => now()->subHours(4),
        ]);

        QaThread::factory()
            ->forUser($student)
            ->forCertification($certification)
            ->resolved()
            ->create([
                'title' => '学習時間の記録が反映されないことがありました',
                'body' => 'ブラウザを閉じたときに学習時間が記録されないようです。同じ経験のある方はいますか。',
                'created_at' => now()->subDays(3),
                'updated_at' => now()->subDays(3),
                'resolved_at' => now()->subDays(2),
            ]);
    }

    /**
     * 公開停止中の資格のスレッド。管理者のモデレーション画面にだけ出ることの確認用。
     */
    private function seedUnpublishedCertificationThread(User $student): void
    {
        $unpublished = Certification::query()
            ->where('status', '!=', CertificationStatus::Published->value)
            ->first();

        if ($unpublished === null) {
            return;
        }

        QaThread::factory()
            ->forUser($student)
            ->forCertification($unpublished)
            ->create([
                'title' => '公開停止中の資格に紐づく質問（管理者のみ閲覧できることの確認用）',
                'created_at' => now()->subDays(10),
                'updated_at' => now()->subDays(10),
            ]);
    }
}
