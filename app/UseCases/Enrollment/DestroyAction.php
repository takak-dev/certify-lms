<?php

declare(strict_types=1);

namespace App\UseCases\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Enrollment\EnrollmentInvalidTransitionException;
use App\Models\Enrollment;
use App\Services\DefaultEnrollmentService;
use Illuminate\Support\Facades\DB;

/**
 * 受講生による受講解除(SoftDelete) Action。learning 状態の Enrollment のみ削除可。
 * passed / failed は履歴として残すため拒否する。
 *
 * 当該 Enrollment が受講生のデフォルト資格だった場合は、他の learning|passed 残存件数で自動振替 / NULL リセット。
 *
 * ⚠️ 配下の個人目標は物理削除する(decisions #135 / 面談2 Q44)。
 *    受講登録そのものは論理削除なので、外部キーの cascade は発火しない。アプリ側で明示的に消す。
 */
final class DestroyAction
{
    public function __construct(
        private readonly DefaultEnrollmentService $defaultEnrollmentService,
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

            $enrollment->delete();

            $this->defaultEnrollmentService->resolveAfterStatusChange($user, $enrollment);
        });
    }
}
