<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Http\Requests\Meeting\AvailabilityRequest;
use App\Http\Requests\Meeting\IndexAsCoachRequest;
use App\Http\Requests\Meeting\IndexRequest;
use App\Http\Requests\Meeting\StoreRequest;
use App\Http\Requests\Meeting\UpsertMemoRequest;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Services\MeetingQuotaService;
use App\UseCases\Meeting\CancelAction;
use App\UseCases\Meeting\FetchAvailabilityAction;
use App\UseCases\Meeting\IndexAction;
use App\UseCases\Meeting\IndexAsCoachAction;
use App\UseCases\Meeting\ShowAction;
use App\UseCases\Meeting\StoreAction;
use App\UseCases\Meeting\UpsertMemoAction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 1on1 面談予約 (Meeting) の HTTP エントリポイント。
 *
 * 受講生視点(index / show / create / store / cancel / fetchAvailability)とコーチ視点
 * (indexAsCoach / upsertMemo)を 1 Controller に集約する。
 *
 * 業務ロジックとデータ取得は app/UseCases/Meeting/ の Action が持ち、本 Controller は
 * 受付(FormRequest の値取り出し・Carbon への変換)、認可委譲($this->authorize() または
 * FormRequest::authorize())、レスポンス整形(view / redirect / JSON)だけを行う(T-A-02)。
 *
 * create / createFallback は T-A-02 の対象外で、Action を持たない。原典が対象として列挙した
 * 取得系は 4 つ(受講生向け一覧 / コーチ向け一覧 / 面談詳細 / 空き枠取得)で、この 2 つは入っていない
 * ——スコープを広げないことを優先した(decisions #218)。
 * ⚠️ createFallback() は `whereIn(...)->with(...)->get()` のクエリを持っており、上段の
 *    「データ取得は Action が持つ」から外れる。同型の MockExamCatalogController::fallbackIndex() も
 *    Controller にクエリを残したままである一方、BrowseController::index() は Learning\IndexAction に
 *    委譲済みで、同型 3 つが 2 対 1 に割れている。揃えるなら模試と面談を同時に別 PR で行う(#218)。
 */
class MeetingController extends Controller
{
    /**
     * 受講生本人の面談一覧。filter (upcoming/past/all) クエリで履歴を切り替える。
     */
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $filter = $request->validated('filter') ?? 'upcoming';

        // Action は meetings と meetingsRemaining を配列で返す。view に渡すキーを追えるよう明示で取り出す
        $data = $action($request->user(), $filter);

        return view('meeting.index', [
            'meetings' => $data['meetings'],
            'meetingsRemaining' => $data['meetingsRemaining'],
            // filter は DB を引かない入力のエコー(検索欄の選択状態)なので Controller に残す
            'filter' => $filter,
        ]);
    }

    /**
     * コーチ宛の面談一覧。担当受講生 / 受講登録での絞り込みを併せて提供する。
     */
    public function indexAsCoach(IndexAsCoachRequest $request, IndexAsCoachAction $action): View
    {
        $filters = $request->validated();
        $filter = $filters['filter'] ?? 'upcoming';
        $studentId = $filters['student'] ?? null;
        $enrollmentId = $filters['enrollment'] ?? null;

        return view('meeting.coach.index', [
            // 末尾 2 つが同じ ?string で取り違えても静かに通るため、名前付き引数で呼ぶ
            'meetings' => $action(
                coach: $request->user(),
                filter: $filter,
                studentId: $studentId,
                enrollmentId: $enrollmentId,
            ),
            // 以下 3 つは DB を引かない入力のエコー(絞り込み欄の選択状態)なので Controller に残す
            'filter' => $filter,
            'studentFilter' => $studentId,
            'enrollmentFilter' => $enrollmentId,
        ]);
    }

    /**
     * 面談詳細(当事者共通)。Policy で coach/student の閲覧範囲を絞る。
     */
    public function show(Meeting $meeting, ShowAction $action): View
    {
        $this->authorize('view', $meeting);

        return view('meeting.show', [
            'meeting' => $action($meeting),
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
     * ⚠️ 予約の成否を分ける処理順(残回数の事前チェック → 候補コーチの確定 → トランザクション)と
     * UNIQUE 違反のリトライは StoreAction が持つ。触る前に StoreAction の PHPDoc を読むこと。
     */
    public function store(Enrollment $enrollment, StoreRequest $request, StoreAction $action): RedirectResponse
    {
        // HTTP の文字列を Carbon / string に直して Action へ渡す。FormRequest は Controller で止める
        $meeting = $action(
            $enrollment,
            Carbon::parse($request->validated('scheduled_at')),
            $request->validated('topic'),
        );

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談を予約しました。');
    }

    /**
     * 当事者(受講生 or コーチ)による面談キャンセル。
     * reserved かつ開始前のみキャンセル可。消費済の面談回数 1 回分を返却する。
     */
    public function cancel(Meeting $meeting, CancelAction $action): RedirectResponse
    {
        $this->authorize('cancel', $meeting);

        // 操作者(= canceled_by_user_id と通知先を決める)は Controller が取って Action へ渡す
        $action($meeting, auth()->user());

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談をキャンセルしました。面談回数を返却しました。');
    }

    /**
     * 担当コーチによる面談メモ作成・更新。canceled の面談にはメモを残せない。
     */
    public function upsertMemo(Meeting $meeting, UpsertMemoRequest $request, UpsertMemoAction $action): RedirectResponse
    {
        $action($meeting, $request->validated('body'));

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談メモを保存しました。');
    }

    /**
     * 予約画面が呼ぶ空き枠取得 JSON エンドポイント。
     */
    public function fetchAvailability(Enrollment $enrollment, AvailabilityRequest $request, FetchAvailabilityAction $action): JsonResponse
    {
        // HTTP で来る文字列を Carbon に変換するのは「受付」の仕事なので Controller に残す
        $date = Carbon::parse($request->validated('date'));

        $slots = $action($enrollment, $date);

        // JSON のキー名と ISO8601 への文字列化は画面(JS)との約束＝レスポンス整形。ここも Controller
        return response()->json([
            'date' => $date->toDateString(),
            'slots' => $slots->map(fn (array $slot) => [
                'slot_start' => $slot['slot_start']->toIso8601String(),
                'slot_end' => $slot['slot_end']->toIso8601String(),
                'available_coach_count' => $slot['available_coach_count'],
            ])->all(),
        ]);
    }
}
