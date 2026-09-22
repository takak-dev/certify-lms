<?php

declare(strict_types=1);

namespace App\UseCases\GoogleCalendar;

use App\Models\Meeting;
use App\Services\GoogleCalendarService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 成立した面談を、担当コーチの Google カレンダーへ登録するユースケース(S-A-01)。
 *
 * 原典「連携済コーチが担当する面談が成立すると、そのコーチの Google カレンダーへ
 * 面談の予定が自動登録される。連携していないコーチには登録しない」。
 *
 * ⚠️ **失敗しても例外を投げない。** 原典 共通の振る舞い「Google との通信に失敗しても、
 *    空き枠の表示・面談の予約・面談のキャンセルといった面談機能の根幹は止まらない」。
 *    予約そのものは既に成立しているので、ここで例外を投げると「予約は DB に入ったのに
 *    画面はエラー」という最悪の食い違いになる。
 *
 * ⚠️ **必ず DB::afterCommit() から呼ぶこと。** トランザクションの中で外部通信すると、
 *    Google の応答を待つ間ずっと行ロックを持ち続ける。B-A-01 で入れた
 *    (coach_id, scheduled_at) UNIQUE の衝突待ちが長引き、予約が詰まる。
 *    手本: StoreAction::__invoke() の MeetingReservedNotification も afterCommit にある。
 */
final class SyncMeetingAction
{
    public function __construct(
        private readonly GoogleCalendarService $google,
    ) {}

    public function __invoke(Meeting $meeting): void
    {
        $credential = $meeting->coach?->googleCredential;

        // 未連携のコーチには登録しない(原典)。ここで静かに戻るのが正しい振る舞い。
        if ($credential === null) {
            return;
        }

        try {
            $eventId = $this->google->createEvent($credential, [
                // decisions #145(面談2 Q18-a)。件名 = 受講生名 + 資格名 / 説明 = 話題 + 面談 URL の案内 /
                // 場所 = コーチの固定面談 URL。
                // ⚠️ 外部サービスへ受講生の氏名と相談の話題が出る。PM の明示的な指示による。
                'summary' => sprintf(
                    '面談: %s / %s',
                    $meeting->student?->name ?? '受講生',
                    $meeting->enrollment?->certification?->name ?? '資格',
                ),
                'description' => $this->description($meeting),
                // 予約時に焼き込んだ URL を使う(コーチが後でプロフィールの URL を変えても、
                // その面談の案内は変わらない)。meetings.meeting_url_snapshot がその役割。
                'location' => (string) ($meeting->meeting_url_snapshot ?? ''),
                'starts_at' => $meeting->scheduled_at,
                // 面談は 60 分固定(create_meetings_table.php の docblock「終了時刻は常に
                // scheduled_at + 60 分」)。
                'ends_at' => $meeting->scheduled_at->copy()->addHour(),
            ]);

            // google_event_id は $fillable に入れてあるので update() で入る。
            $meeting->update(['google_event_id' => $eventId]);
        } catch (Throwable $e) {
            // 例外クラス名も残す。Throwable を全部握る場所は、Google の障害とこちらのバグが
            // 同じ経路で消えるため(MeetingAvailabilityService::googleBusyByCoach() と同じ方針)。
            Log::warning('Google カレンダーへの面談予定の登録に失敗しました。', [
                'meeting_id' => $meeting->id,
                'coach_id' => $meeting->coach_id,
                'exception' => $e::class,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 予定の説明欄。話題と、面談 URL の案内を入れる(decisions #145)。
     */
    private function description(Meeting $meeting): string
    {
        $lines = ['話題:', (string) $meeting->topic];

        if (filled($meeting->meeting_url_snapshot)) {
            $lines[] = '';
            $lines[] = '面談 URL: '.$meeting->meeting_url_snapshot;
        }

        return implode("\n", $lines);
    }
}
