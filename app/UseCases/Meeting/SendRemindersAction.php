<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Console\Commands\Mentoring\SendMeetingRemindersCommand;
use App\Enums\MeetingReminderWindow;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Schedule Command から呼ばれる面談リマインダー配信ユースケース。
 *
 * 渡された面談群の当事者(受講生 / 担当コーチ)へリマインダーを配信する。
 * 状態を変えないため DB::transaction は張らない(AutoCompleteMeetingAction との違い)。
 *
 * ⭐ 面談 1 件ずつではなく「まとまり」を受け取るのは、重複検査を 1 クエリで済ませるため。
 * 1 件ずつ受け取る形にすると、面談の数だけ notifications への問い合わせが発生する(N+1)。
 * 手本は App\Services\LastActivityService::batchLastActivityFor()(PHPDoc に「N+1 回避」と明記)。
 *
 * 冪等性はこのクラスが担保する。専用の送信済みテーブルは持たず、送信済みかどうかは
 * notifications テーブルの `meeting_id` と `reminder_window` を引いて判定する
 * (decisions #36 / #104)。Schedule の withoutOverlapping は同時起動しか防げないため、
 * 手動での再実行や、窓が重なる巡回(1 時間前は 55〜65 分前の 10 分幅を 5 分間隔で見る)は
 * こちら側で弾く必要がある。
 *
 * @see SendMeetingRemindersCommand
 */
final class SendRemindersAction
{
    /**
     * @param Collection<int, Meeting> $meetings 呼び出し側で student / coach を eager load しておく
     *
     * @return int 実際に送った通知の件数(面談数ではなく宛先の延べ数)。配信に失敗した宛先は数えない
     */
    public function __invoke(Collection $meetings, MeetingReminderWindow $window): int
    {
        /** @var array<int, array{Meeting, User}> $targets */
        $targets = [];

        foreach ($meetings as $meeting) {
            // 退会者も User として取れる(student() / coach() は withTrashed。decisions #105)。
            // 配信可否は下の canReceiveNotifications() が status で弾く
            foreach ([$meeting->student, $meeting->coach] as $user) {
                // 受講中でない人・管理者は対象外(decisions #34 / #76)。
                // 通知クラスの shouldSend() も同じ判定を行うが(DeliversToActiveUsersOnly)、
                // あちらは黙って捨てるため件数に数えられない。判定の定義は User 側の 1 箇所を共有している
                if (! $user->canReceiveNotifications()) {
                    continue;
                }

                // 同じ人が両側に立つことはない(coach_id は資格の担当コーチ割当、student_id は
                // 受講登録の本人から入り、ロールを後から書き換える経路がアプリに無い)。
                // よって組の重複排除は不要
                $targets[] = [$meeting, $user];
            }
        }

        if ($targets === []) {
            return 0;
        }

        $alreadySent = $this->alreadySentKeys($targets, $window);

        $sent = 0;
        foreach ($targets as [$meeting, $user]) {
            if (isset($alreadySent[$this->key($user->id, $meeting->id)])) {
                continue;
            }

            try {
                $user->notify(new MeetingReminderNotification($meeting, $window));
                $sent++;
            } catch (Throwable $e) {
                // ⚠️ メールは同期送信で、SMTP が落ちると notify() から例外が抜ける
                // (NotificationSender は例外を握り潰さない)。捕まえないと chunkById のループごと
                // 止まり、まだ処理していない面談が丸ごと未配信のまま終わる。
                //
                // 捕まえても「そのメールを送り直す」ことはしない。原典がリトライをスコープ外に置き
                // (T-A-05 のキュー化で解決する)、アプリ内通知の行は via の順序どおり先に作られるため
                // 次回の実行は送信済みと判定する(decisions #106)。
                // 書き方は既存の唯一の前例に揃える(StartLearningSession.php:38。
                // 関係する ID を並べ、例外はメッセージを出す。本文は英語)。
                // ⚠️ 例外メッセージに載りうる範囲は宛先アドレスだけではない。
                //    ①mail: Symfony Mailer は SMTP の応答文字列をそのまま例外にするため
                //      `550 5.1.1 <foo@example.com> User unknown` の形で宛先が入る
                //    ②database: この catch は via の database チャネルの例外も拾う。QueryException は
                //      実行した SQL に値を埋め込んだ文字列をメッセージにする
                //      (Illuminate/Database/QueryException.php:65 の formatMessage)ため、
                //      **通知データまるごと**(相手方の氏名・面談 URL・宛先 ID)がログに落ちる
                //    ログは storage/logs 配下でリポジトリには入らない。個人情報をログに残さない方針を
                //    作るかは面談で確認する(decisions #106 / pending-list)
                Log::warning('Meeting reminder delivery failed', [
                    'meeting_id' => $meeting->id,
                    'user_id' => $user->id,
                    'window' => $window->value,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * 送信済みの (宛先, 面談) の組を 1 クエリでまとめて引く。
     *
     * 宛先(notifiable_id)で絞るのが肝。notifications のインデックスは
     * (notifiable_type, notifiable_id) 系の 2 本だけで data 列には無いため、
     * 宛先を条件に入れないクエリはインデックスを 1 本も使えず全表走査になる
     * (EXPLAIN で実測。decisions #104)。
     *
     * @param array<int, array{Meeting, User}> $targets
     *
     * @return array<string, true> キーは key() が作る "宛先 ID:面談 ID"
     */
    private function alreadySentKeys(array $targets, MeetingReminderWindow $window): array
    {
        $userIds = [];
        $meetingIds = [];
        foreach ($targets as [$meeting, $user]) {
            $userIds[$user->id] = true;
            $meetingIds[$meeting->id] = true;
        }

        $rows = DB::table('notifications')
            ->where('type', MeetingReminderNotification::class)
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', array_keys($userIds))
            ->where('data->reminder_window', $window->value)
            ->whereIn('data->meeting_id', array_keys($meetingIds))
            ->get(['notifiable_id', 'data']);

        $keys = [];
        foreach ($rows as $row) {
            /** @var array{meeting_id?: string} $data */
            $data = json_decode((string) $row->data, true);
            $keys[$this->key((string) $row->notifiable_id, (string) ($data['meeting_id'] ?? ''))] = true;
        }

        return $keys;
    }

    /**
     * PHP の配列キーは 1 本の文字列にしかできないため、2 つの軸を連結して 1 本にする。
     * ULID は ':' を含まないので、この区切りで取り違えは起きない。
     */
    private function key(string $userId, string $meetingId): string
    {
        return $userId.':'.$meetingId;
    }
}
