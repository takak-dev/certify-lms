<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * キューで送る通知・メールに「失敗したら待ってやり直す」決まりを与えるトレイト(T-A-05 / decisions #271)。
 *
 * 最大 4 回挑戦し、失敗するたびに 10 秒 → 60 秒 → 300 秒待つ。
 * 4 回とも失敗したら failed_jobs に記録され、人が原因を直して `sail artisan queue:retry` で再投入する。
 *
 *   1 回目 失敗 →(10 秒)→ 2 回目 失敗 →(60 秒)→ 3 回目 失敗 →(300 秒)→ 4 回目 失敗 → failed_jobs
 *   途中で成功したら、そこで終わり(残りの挑戦は行わない)
 *
 * ⭐ プロパティを置くだけで効く。Laravel が通知 / メールを包むジョブを作るときに、
 * この 2 つのプロパティを読み取って写す
 * (Illuminate/Notifications/SendQueuedNotifications.php:81,150-156 /
 *  Illuminate/Mail/SendQueuedMailable.php:72,91-97)。
 * ジョブ(app/Jobs)は包まれずにそのまま積まれ、Laravel がジョブ自身のプロパティを直接読む
 * (Illuminate/Queue/Queue.php:188-200 の getJobTries / :209-223 の getJobBackoff)。
 *
 * ⚠️ ShouldQueue と組で使う。ShouldQueue が無いとキューに積まれず同期で送られるため、この設定は何もしない。
 * 通知・メール・ジョブの付け忘れは NotificationDeliveryArchitectureTest が落とす。
 *
 * 通知 6 種類のほか、招待メール(App\Mail\InvitationMail)と
 * お知らせの配る係(App\Jobs\DeliverAnnouncementJob。decisions #274)も use している。
 */
trait RetriesWithBackoff
{
    /** 最大の挑戦回数(初回を含む)。やり直しは最大 3 回 */
    public int $tries = 4;

    /**
     * やり直す前に待つ秒数。n 回目の失敗のあとに n 番目の値だけ待つ。
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];
}
