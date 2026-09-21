<?php

declare(strict_types=1);

namespace App\UseCases\GoogleCalendar;

use App\Models\Meeting;
use App\Services\GoogleCalendarService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * キャンセルされた面談の予定を、担当コーチの Google カレンダーから削除するユースケース(S-A-01)。
 *
 * 原典「その面談がキャンセルされると、登録済の予定も連動して削除される」。
 *
 * ⚠️ SyncMeetingAction と同じく **失敗しても例外を投げず、必ず DB::afterCommit() から呼ぶ**。
 *    キャンセルは既に DB に確定しているので、ここで失敗しても利用者の操作は成功させる
 *    (原典 共通の振る舞い「面談のキャンセル…は止まらない」)。
 */
final class RemoveMeetingEventAction
{
    public function __construct(
        private readonly GoogleCalendarService $google,
    ) {}

    public function __invoke(Meeting $meeting): void
    {
        // Google に登録していない面談は何もしない。この分岐があるおかげで、未連携コーチの面談や
        // 連携前に成立していた面談に対して無駄な通信を投げずに済む
        // (meetings.google_event_id を持つ理由そのもの)。
        if (blank($meeting->google_event_id)) {
            return;
        }

        $credential = $meeting->coach?->googleCredential;

        // ⚠️ 予定は登録済だが、その後コーチが連携を解除した場合。
        //    トークンが無いので削除しに行けない。これは想定内で、支給 Blade が解除時に
        //    「Google 側のイベントは削除されません」と利用者に説明している
        //    (settings/_partials/tab-meeting.blade.php:113-114)。
        //    google_event_id は消さずに残す —— 再連携したときに消しに行ける手がかりになる。
        if ($credential === null) {
            return;
        }

        try {
            $this->google->deleteEvent($credential, (string) $meeting->google_event_id);

            // 消せたら控えも消す。残したままだと「登録済」と見分けがつかなくなる。
            $meeting->update(['google_event_id' => null]);
        } catch (Throwable $e) {
            Log::warning('Google カレンダーからの面談予定の削除に失敗しました。', [
                'meeting_id' => $meeting->id,
                'coach_id' => $meeting->coach_id,
                'exception' => $e::class,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
