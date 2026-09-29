<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CertificationCoachAttached;
use App\Events\CertificationCoachDetached;
use App\Services\ChatMemberSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * 担当コーチ集合の追加 / 解除イベントを受けて、該当資格に紐づく全 ChatRoom の参加者を同期する。
 *
 * 受講登録時点で担当コーチが 0 件だった ChatRoom にも後追いでメンバー追加する責務を持つ。
 * 解除側は ChatMember を削除しないが(過去履歴保持)、本リスナーで再 sync を回せば残ったメンバーの整合は崩れない。
 */
final class SyncChatMembersOnCoachAssignmentChanged implements ShouldQueue
{
    // ⚠️ 接続は sync に固定し、キューに積まずその場で実行する(decisions #266)。
    // 下の $queue は「接続名」ではなく「キューの名前」。QUEUE_CONNECTION=database にすると
    // `database` という名前の列に積まれ、既定の `queue:work`(default しか見ない)が拾わず同期が永久に止まる。
    // T-A-05 の対象は通知・メールで、チャットメンバー同期の振る舞いは変えない。
    // Laravel はリスナーの $connection を接続先として読む(Illuminate/Events/Dispatcher.php:620-622)
    public string $connection = 'sync';

    public string $queue = 'database';

    public function __construct(
        private readonly ChatMemberSyncService $sync,
    ) {}

    public function handle(CertificationCoachAttached|CertificationCoachDetached $event): void
    {
        $this->sync->syncForCertification($event->certification);
    }
}
