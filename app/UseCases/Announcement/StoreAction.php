<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use App\Services\AnnouncementRecipientService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * お知らせを配信するユースケース。本チケットの本体。
 *
 * 手順は 3 つ。
 *   ① 配信対象の受講生を解決する(3 タイプの分岐)
 *   ② announcements に 1 行記録する(配信件数と配信時刻を確定させる)
 *   ③ 対象全員へ通知を送る(アプリ内 + メール)
 *
 * ⭐ ③ だけトランザクションの外(`DB::afterCommit`)に出している(decisions #92)。
 * 中に入れると、送信の途中で失敗したときに記録と database 通知だけが巻き戻り、
 * **送信済みのメールだけが残る**。監査したい場面でこそ履歴が消えることになる。
 * 既存の通知 4 クラスも同じ形(手本: `Chat/StoreMessageAction.php:44`)。
 *
 * ⚠️ そのため `dispatched_count` の意味は「実際に届いた人数」ではなく
 * 「送信を試みた人数」。配信対象は `User::canReceiveNotifications()` より狭いので、
 * 送信直前の判定(`DeliversToActiveUsersOnly`)で落ちる者はおらず、正常時は両者が一致する。
 *
 * ⚠️ キュー化しない。メール配信の非同期化は T-A-05(Advance)の担当。
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
                // Notification::send() は複数の宛先へまとめて送るための入口。
                // 対象が 0 件なら何も起きない(例外にはならない)
                Notification::send($recipients, new AdminAnnouncementNotification($announcement));
            });

            return $announcement;
        });
    }
}
