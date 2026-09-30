<?php

declare(strict_types=1);

namespace App\UseCases\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Enums\MockExamSessionStatus;
use App\Exceptions\Enrollment\EnrollmentInvalidTransitionException;
use App\Models\Enrollment;
use App\Services\DefaultEnrollmentService;
use App\Services\EnrollmentStatsService;
use App\Services\TermJudgementService;
use Illuminate\Support\Facades\DB;

/**
 * 受講生による受講解除(SoftDelete) Action。learning 状態の Enrollment のみ削除可。
 * passed / failed は履歴として残すため拒否する。
 *
 * 当該 Enrollment が受講生のデフォルト資格だった場合は、他の learning|passed 残存件数で自動振替 / NULL リセット。
 *
 * ⚠️ 配下の個人目標は物理削除する(decisions #135 / 面談2 Q44)。
 *    受講登録そのものは論理削除なので、外部キーの cascade は発火しない。アプリ側で明示的に消す。
 *
 * ⚠️ 未開始・受験中の模試はキャンセル済みにする(decisions #285)。受講解除後に続きを解いて提出できないようにするため。
 *    採点済みの結果は残し、解除後も見返せる。
 *
 * ⚠️ 管理者ダッシュボードの集計キャッシュもここで消す(decisions #215)。受講解除は状態が変わらず
 *    EnrollmentStatusChangeService::recordStatusChange() を通らないため、そちらの削除では拾えない。
 */
final class DestroyAction
{
    public function __construct(
        private readonly DefaultEnrollmentService $defaultEnrollmentService,
        private readonly EnrollmentStatsService $stats,
        private readonly TermJudgementService $termJudgement,
    ) {}

    /**
     * @throws EnrollmentInvalidTransitionException
     */
    public function __invoke(Enrollment $enrollment): void
    {
        if ($enrollment->status !== EnrollmentStatus::Learning) {
            throw EnrollmentInvalidTransitionException::forDestroy();
        }

        DB::transaction(function () use ($enrollment) {
            $user = $enrollment->user;

            // 配下の個人目標を物理削除する(decisions #135 / 面談2 Q44「目標の削除(物理削除、履歴は残さない)で良いです」)。
            // 原典 S-B-05 の「親の受講登録が削除された場合、配下の目標も連動して削除される」がこれにあたる。
            //
            // 受講生メモ(S-B-07)は逆に「消さず残す」ので、ここに足さないこと(decisions #47 / #137)。
            $enrollment->goals()->delete();

            // 未開始・受験中の模試をキャンセル済みにする(decisions #285)。受講解除したのに続きを解いて
            // 提出できると、解除した意味が無くなる。キャンセル済みは開始・解答・提出の各 Action が状態で弾き、
            // 画面は既存の「キャンセル済み」表示になる(MockExamSessionController::show())。
            // ⚠️ 受講生の操作としては「未開始」しかキャンセルできない(MockExamSession\DestroyAction)が、
            //    ここは受講解除に伴う後始末なので受験中も含める。採点済み(Submitted / Graded)は結果として残す。
            $enrollment->mockExamSessions()
                ->whereIn('status', [MockExamSessionStatus::NotStarted->value, MockExamSessionStatus::InProgress->value])
                ->update([
                    'status' => MockExamSessionStatus::Canceled->value,
                    'canceled_at' => now(),
                ]);
            // 模試の状態を変えたら学習ターム(current_term)を再判定する契約(TermJudgementService)。
            // 受験中しか無かった受講登録は基礎タームに戻る(解除済みでも受講登録の詳細画面に表示される値)。
            // ⚠️ 受講登録は行ロックを付けて読み直してから渡す。$enrollment はトランザクションの外で読んだもので、
            //    解除と同時に模試の開始が確定していると current_term が古く、再判定が「変化なし」と見て書き込みを飛ばす。
            //    ロックの順序は「受験 → 受講登録」で、模試の Start / Submit と同じ(逆にするとデッドロックしうる)。
            $this->termJudgement->recalculate(
                Enrollment::query()->lockForUpdate()->findOrFail($enrollment->id),
            );

            $enrollment->delete();

            // 集計は論理削除した行を数えないので、受講中の件数・修了率が変わる。
            // 予約するだけで、実際に消えるのはこのトランザクションが確定したとき(decisions #282)。
            $this->stats->forgetAdminDashboardCache();

            $this->defaultEnrollmentService->resolveAfterStatusChange($user, $enrollment);
        });
    }
}
