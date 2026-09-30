<?php

declare(strict_types=1);

namespace App\UseCases\MockExamSession;

use App\Enums\MockExamSessionStatus;
use App\Exceptions\MockExam\MockExamSessionAlreadyStartedException;
use App\Exceptions\MockExam\MockExamSessionEnrollmentUnenrolledException;
use App\Exceptions\MockExam\MockExamUnavailableException;
use App\Models\MockExamSession;
use App\Services\TermJudgementService;
use Illuminate\Support\Facades\DB;

/**
 * NotStarted の受験セッションを InProgress に遷移させるユースケース。
 *
 * `lockForUpdate()` で並列 start を防ぎ、受講解除した資格の受験(decisions #285)と、模試が公開停止された場合は受験開始を拒否する。
 * 遷移後は `TermJudgementService::recalculate` で受講生の current_term を再判定する(基礎ターム → 実践ターム)。
 */
final class StartAction
{
    public function __construct(
        private readonly TermJudgementService $termJudgement,
    ) {}

    /**
     * @throws MockExamSessionAlreadyStartedException
     * @throws MockExamSessionEnrollmentUnenrolledException
     * @throws MockExamUnavailableException
     */
    public function __invoke(MockExamSession $session): MockExamSession
    {
        return DB::transaction(function () use ($session) {
            $session = MockExamSession::query()
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ($session->status !== MockExamSessionStatus::NotStarted) {
                throw new MockExamSessionAlreadyStartedException;
            }

            // 受講解除した資格の受験は続けられない(decisions #285)。解除時にキャンセル済みにするが、
            // 解除より前から残っている受験や、解除と作成が同時に走った場合はここで止める。
            if ($session->enrollment->trashed()) {
                throw new MockExamSessionEnrollmentUnenrolledException;
            }

            if (! $session->mockExam->is_published) {
                throw new MockExamUnavailableException;
            }

            $session->update([
                'status' => MockExamSessionStatus::InProgress->value,
                'started_at' => now(),
            ]);

            $this->termJudgement->recalculate($session->enrollment);

            return $session->refresh();
        });
    }
}
