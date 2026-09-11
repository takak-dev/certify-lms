<?php

declare(strict_types=1);

namespace App\Console\Commands\Mentoring;

use App\Enums\MeetingReminderWindow;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\UseCases\Meeting\SendRemindersAction;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 予約済み面談の事前リマインダーを配信する Schedule Command (S-B-09)。
 *
 * 2 つのタイミングを 1 本のコマンドで扱い、`--window` で切り替える(チケット原典が定めた署名)。
 * 抽出条件だけがタイミングごとに変わり、配信処理は共通。
 *
 * - `--window=eve`             … 前日 18:00 に起動し、翌日 1 日分の予約を対象にする(decisions #50)
 * - `--window=one_hour_before` … 5 分間隔で起動し、開始 55〜65 分前の予約を対象にする(decisions #51)
 *
 * ⚠️ 1 時間前の窓は 10 分幅なのに 5 分間隔で巡回するため、同じ面談が 2 回続けて条件に一致する。
 *    二重配信を防ぐのは SendRemindersAction の重複検査であって、この抽出条件ではない。
 *
 * 同じ window の同時実行は Cache::lock で止める。Schedule の withoutOverlapping は
 * 「schedule:run 経由の起動」しか見ず、しかも 5 分で期限切れになるため足りない(decisions #107)。
 *
 * 抽出は meetings の (status, scheduled_at) INDEX に乗る(create_meetings_table.php 末尾)。
 * 分割は AutoCompleteMeetingsCommand に揃えて chunkById を使う。
 *
 * @see SendRemindersAction
 */
class SendMeetingRemindersCommand extends Command
{
    protected $signature = 'notifications:send-meeting-reminders {--window= : eve または one_hour_before}';

    protected $description = '予約済み面談のリマインダーを配信する（前日 / 開始 1 時間前）';

    public function handle(SendRemindersAction $action): int
    {
        // 不正な値で静かに 0 件になるのを防ぐ。tryFrom は一致しなければ null を返す
        $window = MeetingReminderWindow::tryFrom((string) $this->option('window'));
        if ($window === null) {
            $this->error('--window には eve または one_hour_before を指定してください。');

            return self::FAILURE;
        }

        // ⭐ 同時実行を止めるのはここ。Schedule の withoutOverlapping では足りない(decisions #107)。
        // ①ロックは 5 分で期限切れになる(CacheEventMutex::create が expiresAt * 60 秒で作る)一方、
        //   1 時間前分は 5 分間隔で起動するため、処理が 5 分を超えると次の起動と並走する
        // ②手動実行は schedule:run を通らないのでロックを一切見ない(動作確認で必ず手動実行する)
        // 並走すると両方が「まだ送っていない」と読み、同じ宛先へ 2 通送る。
        $lock = Cache::lock('meeting-reminders:'.$window->value, 600);

        if (! $lock->get()) {
            $this->warn("面談リマインダー（{$window->label()}）は実行中のためスキップしました。");

            // ⚠️ スキップの重さは window で違う。
            // one_hour_before は 5 分後にまた走り、窓が 10 分幅なので同じ面談を拾い直せる。
            // eve は次の起動が 24 時間後で、そのとき対象は「その日から見た翌日」に移っている。
            // つまり**今日スキップした分の前日リマインダーは二度と送られない**。
            // 標準出力は cron の彼方に流れて誰も読まないため、記録を残して後から気づけるようにする。
            Log::warning('Meeting reminder run skipped: lock was held', [
                'window' => $window->value,
                'unrecoverable' => $window === MeetingReminderWindow::Eve,
                // unrecoverable を見た運用者が「どの日の分が落ちたか」を辿れるようにする。
                // 時刻から逆算させない(再実行しても日付が変われば別の日が対象になる)。
                // ⚠️ one_hour_before は窓が 10 分幅で日をまたぐことがあるため、
                //    日付ではなく窓そのものを出す(eve は 1 日分なので日付で足りる)
                'target' => $window === MeetingReminderWindow::Eve
                    ? $this->targetRange($window)[0]->toDateString()
                    : implode('〜', array_map(
                        static fn ($at): string => $at->toDateTimeString(),
                        $this->targetRange($window),
                    )),
            ]);

            // 二重に走らせないための正常な動作なので、コマンド自体は失敗にしない
            return self::SUCCESS;
        }

        try {
            [$from, $to] = $this->targetRange($window);

            $sent = 0;

            Meeting::query()
                ->where('status', MeetingStatus::Reserved->value)
                ->whereBetween('scheduled_at', [$from, $to])
                // 当事者 2 名を Action が参照する。ここで読まないと面談の数だけ users を引くことになる
                ->with(['student', 'coach'])
                ->chunkById(100, function ($meetings) use ($action, $window, &$sent): void {
                    $sent += $action($meetings, $window);
                });

            $this->info("面談リマインダー（{$window->label()}）を {$sent} 件送信しました。");
        } finally {
            // 途中で例外が出てもロックを残さない(残すと最大 10 分間この window が動かない)
            $lock->release();
        }

        return self::SUCCESS;
    }

    /**
     * 対象にする scheduled_at の範囲。whereBetween は両端を含む。
     *
     * @return array{CarbonInterface, CarbonInterface}
     */
    private function targetRange(MeetingReminderWindow $window): array
    {
        return match ($window) {
            // 翌日 00:00 〜 23:59:59。当日の面談は含めない(当日分は 1 時間前リマインダーが拾う)
            MeetingReminderWindow::Eve => [now()->addDay()->startOfDay(), now()->addDay()->endOfDay()],
            MeetingReminderWindow::OneHourBefore => [now()->addMinutes(55), now()->addMinutes(65)],
        };
    }
}
