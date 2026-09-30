<?php

declare(strict_types=1);

namespace App\UseCases\MockExamSession;

use App\Enums\MockExamSessionStatus;
use App\Exceptions\MockExam\MockExamSessionEnrollmentUnenrolledException;
use App\Exceptions\MockExam\MockExamSessionNotInProgressException;
use App\Models\MockExamSession;
use App\Services\TermJudgementService;
use Illuminate\Support\Facades\DB;

/**
 * 受験セッションを提出 → 採点 → ターム再判定の一連を 1 トランザクションで束ねるユースケース。
 *
 * - `lockForUpdate()` で二重提出を排除
 * - 受講解除した資格の受験は提出させない(decisions #285)
 * - 採点(`GradeAction`) は同 transaction 内で呼ぶ
 * - `TermJudgementService::recalculate` も同 transaction
 * - 採点完了後の受講生フィードバックは提出後の result 画面 redirect で完結する
 */
final class SubmitAction
{
    public function __construct(
        private readonly GradeAction $grade,
        private readonly TermJudgementService $termJudgement,
    ) {}

    /**
     * @throws MockExamSessionNotInProgressException
     * @throws MockExamSessionEnrollmentUnenrolledException
     */
    public function __invoke(MockExamSession $session): MockExamSession
    {
        return DB::transaction(function () use ($session) {
            $session = MockExamSession::query()
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ($session->status !== MockExamSessionStatus::InProgress) {
                throw new MockExamSessionNotInProgressException;
            }

            // 受講解除した資格の受験は続けられない(decisions #285)。解除時にキャンセル済みにするが、
            // 解除より前から残っている受験や、解除と作成が同時に走った場合はここで止める。
            if ($session->enrollment->trashed()) {
                throw new MockExamSessionEnrollmentUnenrolledException;
            }

            $session->update([
                'status' => MockExamSessionStatus::Submitted->value,
                'submitted_at' => now(),
            ]);

            ($this->grade)($session);
            $session->refresh();

            $this->termJudgement->recalculate($session->enrollment);

            return $session;
        });
    }
}
