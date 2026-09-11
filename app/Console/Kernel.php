<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // 目標受験日超過の learning Enrollment を failed に自動遷移(他バッチと時刻被りなしで先頭起動)
        $schedule->command('enrollments:fail-expired')->dailyAt('00:00')->withoutOverlapping(5);

        // 期限切れ Invitation の cascade 処理
        $schedule->command('invitations:expire')->dailyAt('00:30')->withoutOverlapping(5);

        // プラン期間満了による自動 graduated 遷移（invitations:expire とロック競合しないよう 00:45 にずらす）
        $schedule->command('users:graduate-expired')->dailyAt('00:45')->withoutOverlapping(5);

        // 滞留 open 学習セッションを max_session_seconds で強制クローズ(ブラウザ閉じ / PC スリープ等の保険)
        $schedule->command('learning:close-stale-sessions')
            ->dailyAt(config('learning.close_stale_schedule', '01:00'))
            ->withoutOverlapping(5);

        // 終了時刻超過の reserved 面談を completed に自動遷移(15 分間隔でリアルタイム性確保)
        $schedule->command('meetings:auto-complete')->cron('*/15 * * * *')->withoutOverlapping(5);

        // 面談リマインダー(前日分)。18:00 に翌日 1 日分をまとめて送る(decisions #50)。
        // 深夜帯の他バッチとは重ならないが、下の 1 時間前分(*/5)と meetings:auto-complete(*/15)とは
        // 18:00 ちょうどに同時に起動する。ロックが別なので競合はしない(理由は下のコメント)
        $schedule->command('notifications:send-meeting-reminders --window=eve')
            ->dailyAt('18:00')
            ->withoutOverlapping(5);

        // 面談リマインダー(開始 1 時間前分)。5 分間隔で 55〜65 分前の予約を巡回する(decisions #51)。
        // ⚠️ withoutOverlapping のロックは「cron 式 + コマンド文字列」の sha1 で決まるため
        //    (Scheduling/Event.php:996)、--window の値が違う上の 1 本とはロックを奪い合わない。
        // ⚠️ ただし withoutOverlapping だけでは二重配信を防げない(5 分で期限切れ / 手動実行に無効)。
        //    本体のロックはコマンド側の Cache::lock にある(decisions #107)
        $schedule->command('notifications:send-meeting-reminders --window=one_hour_before')
            ->cron('*/5 * * * *')
            ->withoutOverlapping(5);
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
