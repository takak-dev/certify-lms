<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use App\Services\CoachMeetingLoadService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\GoogleCalendar\SyncMeetingAction;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 受講生の面談予約(reserved の確定)を行う Action。
 *
 * 認可(受講生ロールか / 自分の Enrollment か)は StoreRequest::authorize() が済ませている前提。
 *
 * ⚠️ **処理の順序そのものが仕様**。上から順に理由があり、入れ替えると過去チケットの修正が壊れる。
 *
 *   [トランザクションの外]
 *     ① 残面談回数の事前チェック … 無資格の POST で外部通信を起こさせない門番(decisions #184)
 *     ② 候補コーチの確定         … 中で Google に問い合わせる。ロックを持ったまま外部通信しない(S-A-01)
 *   [DB::transaction(..., 3) の中]
 *     ③ 残面談回数の再チェック   … 並行予約に対する権威ある判定(①とは役割が違うので両方必要)
 *     ④ validateSlot            … DB だけを見るのでトランザクション内でよい
 *     ⑤ 候補を負荷順に INSERT    … UNIQUE 違反は「そのコーチの獲得失敗」と解釈して次へ(B-A-01)
 *     ⑥ 面談回数の消費
 *     ⑦ afterCommit(通知 / Google 登録)
 *
 * @throws InsufficientMeetingQuotaException 残面談回数が 0(409)
 * @throws MeetingNoAvailableCoachException 候補コーチが居ない / 全員が同時刻に埋まっていた(409)
 */
final class StoreAction
{
    public function __construct(
        private readonly MeetingAvailabilityService $availabilityService,
        private readonly CoachMeetingLoadService $coachLoadService,
        private readonly MeetingQuotaService $quotaService,
        private readonly ConsumeQuotaAction $consumeAction,
        private readonly SyncMeetingAction $syncAction,
    ) {}

    public function __invoke(Enrollment $enrollment, Carbon $scheduledAt, string $topic): Meeting
    {
        $student = $enrollment->user;

        // ⚠️ 候補コーチの確定は **トランザクションの外** で行う(S-A-01)。
        //    この中で MeetingAvailabilityService が Google に問い合わせるため、
        //    トランザクション内に置くと Google の応答を待つ間ずっと行ロックを持ち続け、
        //    B-A-01 の (coach_id, scheduled_at) UNIQUE の衝突待ちが詰まる。
        //    下の DB::transaction(..., 3) はリトライするので、内側に置くと
        //    1 回の予約で最大 3 倍の外部通信が走ることにもなる。
        //    ここで得た候補はあくまで事前フィルタで、最終的な空席判定は
        //    トランザクション内の INSERT(UNIQUE 違反)が担う —— この分担は B-A-01 の設計どおり。
        //
        // ⚠️ ただし **残回数の事前チェックを先に置く**。候補抽出をトランザクションの外へ出したことで、
        //    そのままだと残回数 0 の受講生の POST でも Google への通信が起きるようになってしまう
        //    (MeetingPolicy::create() はロールしか見ないため、受講中なら誰でもここへ到達する)。
        //    時刻を変えながら連打されるとキャッシュも効かず、外部 API のクォータを削られる。
        //    権威ある判定はトランザクション内に残す(下の remaining() )—— こちらは競合対策で、
        //    ここは「無資格のリクエストで外部通信を起こさせない」ための門番。
        if ($this->quotaService->remaining($student) < 1) {
            throw new InsufficientMeetingQuotaException;
        }

        $candidates = $this->findAvailableCoaches($enrollment->certification, $scheduledAt);

        // ⚠️ 第 2 引数の再試行回数は必須(B-A-01)。重複キーの INSERT は 1062 を即返すとは限らず、
        // 先行リクエストが未コミットのうちは待たされる。待ちが innodb_lock_wait_timeout を超えると
        // errno 1205 / 1213 になり、Laravel はこれを UniqueConstraintViolationException ではなく
        // DeadlockException として投げる——下の catch をすり抜けて 500 になり、原典が要求する 409 を返せない。
        // 外側の transaction に回数を渡しておくと、handleTransactionException が rollBack して
        // やり直すため、最終的に 1062(= UNIQUE 違反)の経路に収束する。
        return DB::transaction(function () use (
            $enrollment,
            $student,
            $scheduledAt,
            $topic,
            $candidates,
        ) {
            if ($this->quotaService->remaining($student) < 1) {
                throw new InsufficientMeetingQuotaException;
            }

            // validateSlot() は DB だけを見る(Google は参照しない)ので、トランザクション内でよい。
            $this->availabilityService->validateSlot($enrollment->certification, $scheduledAt);

            if ($candidates->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $candidates = $this->coachLoadService->sortByLoad($candidates);

            // 負荷の少ない順に候補コーチを試し、UNIQUE で弾かれたら次へ進む(B-A-01)。
            // 並行予約では全員が同じスナップショットを読み、sortByLoad が安定ソートで同じ順序を返す
            // (CoachMeetingLoadService.php:65-69)ため、先頭のコーチは必ず衝突する。UNIQUE 違反を即 409 にせず
            // 「そのコーチの獲得に失敗した」と解釈することで、空いているコーチが残っていれば予約を成立させる。
            // 候補抽出はキャンセル済みを占有扱いしない(= canceled が残る枠のコーチも候補に入る)ので、
            // そのコーチが選ばれたときも UNIQUE で弾かれて次へ進む。DB を最終的な空席判定器として使う。
            // INSERT をネストした transaction(SAVEPOINT)に包むのは、衝突後も外側の処理を続けるため
            // (MySQL は重複キーで文単位ロールバックに留まるが、巻き戻し範囲を明示的に限定する)。
            $meeting = null;
            $coach = null;
            $lastConflict = null;

            foreach ($candidates as $candidate) {
                try {
                    $meeting = DB::transaction(fn () => Meeting::create([
                        'enrollment_id' => $enrollment->id,
                        'coach_id' => $candidate->id,
                        'student_id' => $student->id,
                        'scheduled_at' => $scheduledAt,
                        'status' => MeetingStatus::Reserved->value,
                        'topic' => $topic,
                        'meeting_url_snapshot' => $candidate->meeting_url,
                    ]));
                    $coach = $candidate;

                    break;
                } catch (UniqueConstraintViolationException $e) {
                    // ⚠️ 「同コーチ・同時刻」以外の UNIQUE 違反まで飲み込まない(B-A-01)。
                    // Laravel の UniqueConstraintViolationException は errno 1062 の文字列一致だけで判定しており、
                    // **どの索引で落ちたかは見ていない**。将来 meetings に別の UNIQUE が増えると、
                    // その違反まで黙って「次のコーチへ」に吸収されて 409 に化ける。索引名で判別して取りこぼしは落とす。
                    if (! str_contains($e->getMessage(), 'meetings_coach_id_scheduled_at_unique')) {
                        throw $e;
                    }

                    $lastConflict = $e;

                    continue;
                }
            }

            // 候補はいたが全員が同時刻に埋まっていた。
            // 最後の UNIQUE 違反を previous に繋いでおく(支給コードもそうしていた)——
            // 繋がないと「なぜ 409 になったか」がログに何も残らない。
            if ($meeting === null) {
                throw new MeetingNoAvailableCoachException($lastConflict);
            }

            $transaction = ($this->consumeAction)($student, $meeting->id);
            $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);

            $fresh = $meeting->fresh();

            // 予約が確定したら担当コーチへ通知する(S-B-04)。予約した受講生本人には送らない——
            // 予約画面が「予約完了後、コーチに通知メールが届きます」と明記している
            // (meeting/create.blade.php:158。decisions #77)。
            // 通知は afterCommit に置く。この先で例外が出て予約が巻き戻ったときに通知だけ残さないため。
            DB::afterCommit(function () use ($fresh, $coach): void {
                $coach->notify(new MeetingReservedNotification($fresh));
            });

            // 連携済コーチの Google カレンダーへ予定を登録する(S-A-01)。
            // ⚠️ 通知と同じく afterCommit に置く。理由は 2 つ。
            //    ① トランザクションの中で外部通信すると、Google の応答を待つ間ずっと行ロックを
            //       持ち続け、B-A-01 の (coach_id, scheduled_at) UNIQUE の衝突待ちが詰まる。
            //    ② 予約がロールバックされたときに Google 側だけ予定が残るのを防ぐ。
            // Action 側で失敗を握るので、ここで try-catch はしない(原典「予約は止まらない」)。
            DB::afterCommit(function () use ($fresh): void {
                ($this->syncAction)($fresh);
            });

            return $fresh;
        }, 3);
    }

    /**
     * 担当コーチ集合のうち、(1) その時刻に 60 分スロットを提供でき、
     * (2) 当該時刻に reserved / completed の Meeting を持たないコーチ集合を返す。
     *
     * ⚠️ (1) は MeetingAvailabilityService::coachIdsOfferingSlot() に委ねる(B-A-01)。
     * ここで SQL の範囲判定を書くと、空き枠表示の格子と基準がずれて
     * 「画面に出ない時刻が POST で通る」——稼働の終わり(9:00-17:30 の 17:00)でも
     * 始まり(9:30 始まりの 10:00)でも成立することを実測で確認した。
     *
     * ⚠️ (2) は **status を見る**(canceled はすり抜けて候補に残る)。UNIQUE と基準を揃えないのは、
     * 揃えると __invoke() のリトライループと二重の対処になるため(decisions #167)。
     * canceled が残る枠のコーチが選ばれた場合は INSERT が UNIQUE で弾かれ、ループが次の候補へ進む。
     *
     * @return Collection<int, User>
     */
    private function findAvailableCoaches(Certification $certification, Carbon $scheduledAt): Collection
    {
        $offeringCoachIds = $this->availabilityService->coachIdsOfferingSlot($certification, $scheduledAt);

        if ($offeringCoachIds === []) {
            return collect();
        }

        return $certification->coaches()
            ->whereIn('users.id', $offeringCoachIds)
            ->whereDoesntHave('meetingsAsCoach', function ($q) use ($scheduledAt) {
                $q->where('scheduled_at', $scheduledAt)
                    ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value]);
            })
            ->get();
    }
}
