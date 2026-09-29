<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Jobs\DeliverAnnouncementJob;
use App\Models\Announcement;
use App\Models\User;
use App\Services\AnnouncementRecipientService;
use Illuminate\Support\Facades\DB;

/**
 * お知らせを配信するユースケース。本チケットの本体。
 *
 * 手順は 3 つ。
 *   ① 配信対象の受講生を解決する(3 タイプの分岐)
 *   ② announcements に 1 行記録する(配信件数と配信時刻を確定させる)
 *   ③ 対象全員へ通知を送るための「配る係」のジョブを 1 件積む(送信は worker。T-A-05 / decisions #274)
 *
 * ⭐ ③ だけトランザクションの外(`DB::afterCommit`)に出している(decisions #92)。
 * T-A-05 以降は、巻き戻ったのに配る係だけがキューに残るのを防ぐ意味もある(decisions #270)。
 * 中に入れると、送信の途中で失敗したときに記録と database 通知だけが巻き戻り、
 * **送信済みのメールだけが残る**。監査したい場面でこそ履歴が消えることになる。
 * 既存の通知 4 クラスも同じ形(手本: `Chat/StoreMessageAction.php:44`)。
 *
 * ⚠️ そのため `dispatched_count` の意味は「実際に届いた人数」ではなく
 * 「送信を試みた人数」(＝キューに積んだ人数)。配信対象は `User::canReceiveNotifications()` より狭いので、
 * 積んだ時点では送信直前の判定(`DeliversToActiveUsersOnly`)で落ちる者はいない。
 * ただし判定は worker が送る時点で行われるため、積んでから送るまでの間に退会・修了した人には届かず、
 * その場合だけ両者がずれる(T-A-05)。
 *
 * ⭐ 画面の処理では「配る係」のジョブ(DeliverAnnouncementJob)を 1 件積むだけで返る(T-A-05 / decisions #274)。
 * 宛先ごとの通知ジョブへの展開と送信は worker(`sail artisan queue:work`)が行うので、
 * 待ち時間が受講生の人数に比例しない。宛先 × チャネルごとに別々のジョブになるので、
 * 1 人の送信失敗が他の人やこのリクエストに伝播しない。
 */
final class StoreAction
{
    /**
     * 配信対象の解決は Service に任せる。コンストラクタで受け取ると
     * Controller 側は StoreAction だけを知っていればよくなる
     * (手本: Enrollment/ReceiveCertificateAction.php:34 が CompletionEligibilityService を注入している)。
     */
    public function __construct(
        private readonly AnnouncementRecipientService $recipients,
    ) {}

    /**
     * @param array{
     *     title: string,
     *     body: string,
     *     target_type: string,
     *     target_certification_id?: string|null,
     *     target_user_id?: string|null,
     * } $validated Announcement/StoreRequest::rules() で検証済
     */
    public function __invoke(User $admin, array $validated): Announcement
    {
        return DB::transaction(function () use ($admin, $validated): Announcement {
            // 一括代入を許すのはフォームの 5 項目だけ(Announcement::$fillable)
            $announcement = new Announcement($validated);

            // 配信対象の解決は専用の Service に切り出してある(AnnouncementSeeder も同じものを使う)。
            // まだ保存していない Announcement を渡せる——件数を決めるために先に対象を知る必要があるため
            $recipients = $this->recipients->resolve($announcement);

            // 配信実績と配信者はフォームの値ではないのでここで確定させる
            $announcement->created_by_user_id = $admin->id;
            $announcement->dispatched_at = now();
            // 対象 0 件でも配信は成功扱いで 0 を記録する(decisions #49。誤操作の証跡として残す)
            $announcement->dispatched_count = $recipients->count();
            $announcement->save();

            DB::afterCommit(function () use ($recipients, $announcement): void {
                // 宛先はこの時点で確定させ、ID の一覧として渡す(配信件数と実際の相手を一致させるため。decisions #274)。
                // 対象が 0 件でもジョブは積む。展開する宛先が無いだけで、何も送られない
                DeliverAnnouncementJob::dispatch($announcement, $recipients->pluck('id')->all());
            });

            return $announcement;
        });
    }
}
