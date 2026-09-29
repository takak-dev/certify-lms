<?php

declare(strict_types=1);

namespace App\UseCases\Learning;

use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;
use App\Models\Part;
use App\Models\User;
use App\Services\LearningProgressService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /learning/parts/{part} (3 階層目、Chapter 一覧) のデータを準備する Action。
 *
 * 公開済 Chapter 一覧 + Part / 資格の Published 確認 (どちらかが非公開なら 404) に加え、
 * 各 Chapter の Section 総数(withCount)と読了済 Section 数(LearningProgressService)を集計して Blade に渡す
 * (Chapter 完了バッジの表示用)。受講生が当該資格に未登録の場合は完了数 0 として扱う。
 */
final class ShowPartAction
{
    public function __construct(
        private readonly LearningProgressService $learningProgress,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Part $part, User $student): array
    {
        $part->loadMissing('certification');

        if ($part->status !== ContentStatus::Published
            || $part->certification === null
            || $part->certification->status !== CertificationStatus::Published) {
            throw new NotFoundHttpException;
        }

        $chapters = $part->chapters()
            ->where('status', ContentStatus::Published->value)
            ->ordered()
            ->withCount([
                'sections as sections_total_count' => fn ($q) => $q
                    ->where('status', ContentStatus::Published->value),
            ])
            ->get();

        $enrollment = $student->enrollments()
            ->where('certification_id', $part->certification_id)
            ->first();

        // 受講登録が無いときは完了数 0 として扱う。画面から来る経路では PartViewPolicy が受講登録を必須にして
        // 止めるので通常は到達しない。Action を単体で呼ばれたときのための保険(改修前と同じ振る舞い)。
        // Service は Enrollment を必須で受け取るので、null の判定はこちらに残す。
        $completedByChapter = $enrollment !== null
            ? $this->learningProgress->completedSectionCountsByChapter($enrollment, $chapters->pluck('id'))
            : [];

        return [
            'part' => $part->load('certification'),
            'chapters' => $chapters,
            'completedByChapter' => $completedByChapter,
        ];
    }
}
