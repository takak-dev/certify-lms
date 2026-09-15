<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Http\Requests\Meeting\AvailabilityRequest;
use App\Http\Requests\Meeting\IndexAsCoachRequest;
use App\Http\Requests\Meeting\IndexRequest;
use App\Http\Requests\Meeting\StoreRequest;
use App\Http\Requests\Meeting\UpsertMemoRequest;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use App\Services\CoachMeetingLoadService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * 1on1 面談予約 (Meeting) の HTTP エントリポイント。
 *
 * 受講生視点(index / show / create / store / cancel / fetchAvailability)とコーチ視点
 * (indexAsCoach / upsertMemo)を 1 Controller に集約する。予約 / キャンセル / メモ保存の
 * 状態変更系は残面談回数の消費・返却、通知発火、トランザクション境界を method 内で扱い、
 * 取得系はクエリ組み立てを method 内で行う。認可は $this->authorize() または FormRequest::authorize()。
 */
class MeetingController extends Controller
{
    /**
     * 受講生本人の面談一覧。filter (upcoming/past/all) クエリで履歴を切り替える。
     */
    public function index(IndexRequest $request, MeetingQuotaService $meetingQuota): View
    {
        $filter = $request->validated('filter') ?? 'upcoming';

        $query = Meeting::query()
            ->with(['enrollment.certification', 'coach'])
            ->forStudent($request->user())
            ->orderByDesc('scheduled_at');

        $meetings = match ($filter) {
            'past' => $query->past()->paginate(20),
            'all' => $query->paginate(20),
            default => $query->upcoming()->paginate(20),
        };

        return view('meeting.index', [
            'meetings' => $meetings,
            'filter' => $filter,
            'meetingsRemaining' => $meetingQuota->remaining($request->user()),
        ]);
    }

    /**
     * コーチ宛の面談一覧。担当受講生 / 受講登録での絞り込みを併せて提供する。
     */
    public function indexAsCoach(IndexAsCoachRequest $request): View
    {
        $filters = $request->validated();
        $filter = $filters['filter'] ?? 'upcoming';
        $studentId = $filters['student'] ?? null;
        $enrollmentId = $filters['enrollment'] ?? null;

        $query = Meeting::query()
            ->with(['enrollment.certification', 'student'])
            ->forCoach($request->user())
            ->when($studentId, fn ($q, $id) => $q->where('student_id', $id))
            ->when($enrollmentId, fn ($q, $id) => $q->where('enrollment_id', $id));

        // upcoming: 次の面談を一番上に置く (昇順) / past + all: 直近の活動を一番上 (降順)
        $meetings = match ($filter) {
            'past' => $query->past()->orderByDesc('scheduled_at')->paginate(20),
            'all' => $query->orderByDesc('scheduled_at')->paginate(20),
            default => $query->upcoming()->orderBy('scheduled_at')->paginate(20),
        };

        return view('meeting.coach.index', [
            'meetings' => $meetings,
            'filter' => $filter,
            'studentFilter' => $studentId,
            'enrollmentFilter' => $enrollmentId,
        ]);
    }

    /**
     * 面談詳細(当事者共通)。Policy で coach/student の閲覧範囲を絞る。
     */
    public function show(Meeting $meeting): View
    {
        $this->authorize('view', $meeting);

        $meeting->loadMissing([
            'enrollment.certification',
            'coach',
            'student',
            'canceledBy',
            'meetingMemo',
        ]);

        return view('meeting.show', [
            'meeting' => $meeting,
        ]);
    }

    /**
     * 予約画面(受講生): URL に Enrollment を含む正規ルートで表示する。
     */
    public function create(Enrollment $enrollment, MeetingQuotaService $meetingQuota): View
    {
        $this->authorize('create', Meeting::class);

        abort_unless($enrollment->user_id === auth()->id(), 403);
        abort_unless($enrollment->status === EnrollmentStatus::Learning, 403);

        $enrollment->loadMissing('certification');

        return view('meeting.create', [
            'enrollment' => $enrollment,
            'meetingsRemaining' => $meetingQuota->remaining(auth()->user()),
        ]);
    }

    /**
     * 予約画面のエントリポイント(URL に Enrollment 無し)。
     * `resolve-default-enrollment` Middleware が default 資格に redirect するため、
     * 本 method に到達するのは default 未設定 + 残存 Enrollment が 0 件 or 2+ 件のケース。
     */
    public function createFallback(): View
    {
        $user = auth()->user();
        $enrollments = $user
            ?->enrollments()
            ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])
            ->with('certification')
            ->get();

        return view('meeting.empty-state', [
            'enrollments' => $enrollments ?? collect(),
        ]);
    }

    /**
     * 受講生の予約申請。残面談回数を確認し、担当コーチを**負荷の少ない順に試して** reserved で確定する。
     *
     * 同時刻 race condition は (coach_id, scheduled_at) UNIQUE 違反として検知するが、**即 409 にはしない**——
     * 「そのコーチの獲得に失敗した」と解釈して次の候補へ進み、**全員が弾かれたときだけ 409** を投げる(B-A-01)。
     * 空席の判定を DB に委ねる設計なので、`validateSlot()` は満枠を見ない(decisions #167)。
     */
    public function store(
        Enrollment $enrollment,
        StoreRequest $request,
        MeetingAvailabilityService $availabilityService,
        CoachMeetingLoadService $coachLoadService,
        MeetingQuotaService $quotaService,
        ConsumeQuotaAction $consumeAction,
    ): RedirectResponse {
        $scheduledAt = Carbon::parse($request->validated('scheduled_at'));
        $topic = $request->validated('topic');
        $student = $enrollment->user;

        // ⚠️ 第 2 引数の再試行回数は必須(B-A-01)。重複キーの INSERT は 1062 を即返すとは限らず、
        // 先行リクエストが未コミットのうちは待たされる。待ちが innodb_lock_wait_timeout を超えると
        // errno 1205 / 1213 になり、Laravel はこれを UniqueConstraintViolationException ではなく
        // DeadlockException として投げる——下の catch をすり抜けて 500 になり、原典が要求する 409 を返せない。
        // 外側の transaction に回数を渡しておくと、handleTransactionException が rollBack して
        // やり直すため、最終的に 1062(= UNIQUE 違反)の経路に収束する。
        $meeting = DB::transaction(function () use (
            $enrollment,
            $student,
            $scheduledAt,
            $topic,
            $availabilityService,
            $coachLoadService,
            $quotaService,
            $consumeAction,
        ) {
            if ($quotaService->remaining($student) < 1) {
                throw new InsufficientMeetingQuotaException;
            }

            $availabilityService->validateSlot($enrollment->certification, $scheduledAt);

            $candidates = $this->findAvailableCoaches($enrollment->certification, $scheduledAt, $availabilityService);
            if ($candidates->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $candidates = $coachLoadService->sortByLoad($candidates);

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

            $transaction = ($consumeAction)($student, $meeting->id);
            $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);

            $fresh = $meeting->fresh();

            // 予約が確定したら担当コーチへ通知する(S-B-04)。予約した受講生本人には送らない——
            // 予約画面が「予約完了後、コーチに通知メールが届きます」と明記している
            // (meeting/create.blade.php:158。decisions #77)。
            // 通知は afterCommit に置く。この先で例外が出て予約が巻き戻ったときに通知だけ残さないため。
            DB::afterCommit(function () use ($fresh, $coach): void {
                $coach->notify(new MeetingReservedNotification($fresh));
            });

            return $fresh;
        }, 3);

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談を予約しました。');
    }

    /**
     * 当事者(受講生 or コーチ)による面談キャンセル。
     * reserved かつ開始前のみキャンセル可。消費済の面談回数 1 回分を返却する。
     */
    public function cancel(
        Meeting $meeting,
        RefundQuotaAction $refundAction,
    ): RedirectResponse {
        $this->authorize('cancel', $meeting);

        $actor = auth()->user();

        DB::transaction(function () use ($meeting, $actor, $refundAction) {
            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== MeetingStatus::Reserved) {
                throw MeetingStatusTransitionException::forCancel();
            }

            if ($locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw new MeetingAlreadyStartedException;
            }

            $locked->update([
                'status' => MeetingStatus::Canceled->value,
                'canceled_by_user_id' => $actor->id,
                'canceled_at' => now(),
            ]);

            // 消費済の 1 回分を返却する。残数は取引の積み上げ(MeetingQuotaService::remaining)で求めるため、
            // 返却は refunded を 1 行足して表す(max_meetings はプラン付与の総数を持つ列であり、
            // キャンセル返却で触る列ではない)。
            // 返却先はキャンセル操作者ではなく面談の受講生: コーチがキャンセルした場合も回数は受講生に戻る。
            // 上の Reserved ガードと同じロック・同じトランザクション内に置くことで、二重返却と
            // 「status だけ canceled で返却されていない」状態の両方を防ぐ。
            ($refundAction)($locked->student, $locked->id);

            // キャンセルした本人ではなく「相手方」へ通知する(S-B-04)。
            // キャンセル確認画面が「相手方に通知メールが届きます」と明記している
            // (meeting/_modals/cancel-confirm.blade.php:21。decisions #77)。
            $counterpart = $actor->id === $locked->student_id
                ? $locked->coach
                : $locked->student;

            DB::afterCommit(function () use ($locked, $counterpart): void {
                $counterpart?->notify(new MeetingCanceledNotification($locked));
            });
        });

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談をキャンセルしました。面談回数を返却しました。');
    }

    /**
     * 担当コーチによる面談メモ作成・更新。canceled の面談にはメモを残せない。
     */
    public function upsertMemo(Meeting $meeting, UpsertMemoRequest $request): RedirectResponse
    {
        $body = $request->validated('body');

        DB::transaction(function () use ($meeting, $body) {
            if (! in_array($meeting->status, [MeetingStatus::Reserved, MeetingStatus::Completed], true)) {
                throw MeetingStatusTransitionException::forMemo();
            }

            MeetingMemo::updateOrCreate(
                ['meeting_id' => $meeting->id],
                ['body' => $body],
            );
        });

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談メモを保存しました。');
    }

    /**
     * 予約画面が呼ぶ空き枠取得 JSON エンドポイント。
     */
    public function fetchAvailability(Enrollment $enrollment, AvailabilityRequest $request, MeetingAvailabilityService $availabilityService): JsonResponse
    {
        $date = Carbon::parse($request->validated('date'));
        $slots = $availabilityService->slotsForCertification(
            $enrollment->loadMissing('certification')->certification,
            $date,
        );

        return response()->json([
            'date' => $date->toDateString(),
            'slots' => $slots->map(fn (array $slot) => [
                'slot_start' => $slot['slot_start']->toIso8601String(),
                'slot_end' => $slot['slot_end']->toIso8601String(),
                'available_coach_count' => $slot['available_coach_count'],
            ])->all(),
        ]);
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
     * 揃えると store() のリトライループと二重の対処になるため(decisions #167)。
     * canceled が残る枠のコーチが選ばれた場合は INSERT が UNIQUE で弾かれ、ループが次の候補へ進む。
     *
     * @return Collection<int, User>
     */
    private function findAvailableCoaches(
        Certification $certification,
        Carbon $scheduledAt,
        MeetingAvailabilityService $availabilityService,
    ): Collection {
        $offeringCoachIds = $availabilityService->coachIdsOfferingSlot($certification, $scheduledAt);

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
