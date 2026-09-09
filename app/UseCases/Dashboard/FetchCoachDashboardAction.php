<?php

declare(strict_types=1);

namespace App\UseCases\Dashboard;

use App\Enums\EnrollmentStatus;
use App\Enums\MeetingStatus;
use App\Enums\QaThreadStatus;
use App\Http\Controllers\DashboardController;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\QaThread;
use App\Models\User;
use App\Services\ChatUnreadCountService;
use App\UseCases\Dashboard\ViewModels\CoachDashboardViewModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * コーチダッシュボードの ViewModel を組み立てる Action。
 *
 * 担当資格に紐付く Enrollment 一覧(certification.coaches 経由) + 今日 / 明日の面談予約 +
 * 未読 chat 件数 + 未読 chat ルーム上位 5 件 + 未回答 Q&A 件数 + 直近 Q&A 上位 5 件 を集約する。
 *
 * 担当受講生一覧は表示専用(ソートなし、最終活動日は担当受講生ごとに最終学習セッションから取得)。
 * 弱点カテゴリ集約 / 受講生メモ表示 / 滞留検知は本ロールでは表示しない(個別画面で対応)。
 *
 * @see DashboardController::index()
 */
final class FetchCoachDashboardAction
{
    use HasDashboardSafeFetch;

    public function __construct(
        private readonly ChatUnreadCountService $chatUnread,
    ) {}

    public function __invoke(User $coach): CoachDashboardViewModel
    {
        $coachingCertificationIds = $coach->coachingCertificationIds();

        $assignedEnrollments = Enrollment::query()
            ->whereIn('certification_id', $coachingCertificationIds)
            ->whereIn('status', [EnrollmentStatus::Learning, EnrollmentStatus::Passed])
            // 一覧の各行が参照する関連を先読みする。行ごとに引くと受講生の数だけクエリが増える(N+1)。
            // 関連 1 つにつき 1 本の IN 句クエリで済み、件数が増えても本数は変わらない。
            ->with(['user', 'certification'])
            // 最終活動日時は MAX(started_at) という集計値なので with() では取れない。
            // withMax は SELECT 句にサブクエリを埋め込むため、追加のクエリを発行しない(withCount と同じ仕組み)。
            // この手段は規約側の指定でもある: DashboardArchitectureTest::test_enrollment_model_does_not_define_last_learning_session_relation
            // が「Enrollment.lastLearningSession リレーションは禁止(Action 側で withMax を使う)」と明記している。
            //
            // `as last_activity_at` の別名は必須: 既定の属性名は learning_sessions_max_started_at になり、
            // 表示側(dashboard/_partials/coach/assigned-students-list.blade.php の $lastActivityAt)が読む名前と
            // 食い違う。例外にならないまま全員「記録なし」と表示されるだけなので、テストでも気づけない。
            //
            // LastActivityService は使わない: あちらは MAX(ended_at) と演習解答の MAX(answered_at) を統合した
            // 別定義で、本画面が表示してきた MAX(started_at) とは値が変わる。本チケットは振る舞い不変が要件。
            ->withMax('learningSessions as last_activity_at', 'started_at')
            ->get();

        $todayAndTomorrowMeetings = Meeting::query()
            ->where('coach_id', $coach->id)
            ->where('status', MeetingStatus::Reserved)
            ->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()->addDay()])
            ->with(['student', 'enrollment.certification'])
            ->orderBy('scheduled_at')
            ->get();

        return new CoachDashboardViewModel(
            assignedEnrollments: $assignedEnrollments,
            todayAndTomorrowMeetings: $todayAndTomorrowMeetings,
            unreadChatCount: $this->safe(fn () => $this->chatUnread->roomCountForUser($coach)),
            recentUnreadChatRooms: $this->safe(fn () => $this->fetchRecentUnreadChatRooms($coach)),
            unansweredQaCount: $this->safe(fn () => $this->fetchUnansweredQaCount($coachingCertificationIds)),
            recentQaThreads: $this->safe(fn () => $this->fetchRecentUnansweredQaThreads($coachingCertificationIds)),
        );
    }

    /**
     * コーチ宛て未読 chat ルームの上位 5 件を返す。
     * 未読件数 0 のルームは除外、未読件数で並べ替えはせず最終メッセージ時刻順とする(`scopeOrderByLastMessage`)。
     *
     * @return Collection<int, ChatRoom>
     */
    private function fetchRecentUnreadChatRooms(User $coach): Collection
    {
        $rooms = ChatRoom::query()
            ->forUser($coach)
            ->with(['enrollment.user', 'enrollment.certification', 'latestMessage'])
            ->orderByLastMessage()
            ->get();

        return $rooms
            ->filter(fn (ChatRoom $room) => $this->chatUnread->messageCountInRoom($room, $coach) > 0)
            ->take(5)
            ->values();
    }

    /**
     * @param array<int, string> $certificationIds
     */
    private function fetchUnansweredQaCount(array $certificationIds): int
    {
        // 質問掲示板ルートが未登録の環境では集計しない（機能未提供時の防御、件数 0）
        if (! Route::has('qa-board.index')) {
            return 0;
        }

        return QaThread::query()
            ->whereIn('certification_id', $certificationIds)
            // 担当資格が公開停止になった場合、コーチには見せない(S-B-01 のアクセス制御に揃える)
            ->wherePublishedCertification()
            ->where('status', QaThreadStatus::Open)
            ->whereDoesntHave('replies')
            ->count();
    }

    /**
     * 担当資格スコープの未回答 Q&A スレッド上位 5 件を新着順で返す。
     *
     * @param array<int, string> $certificationIds
     *
     * @return Collection<int, QaThread>
     */
    private function fetchRecentUnansweredQaThreads(array $certificationIds): Collection
    {
        // 質問掲示板ルートが未登録の環境では空一覧を返す（機能未提供時の防御）
        if (! Route::has('qa-board.index')) {
            return collect();
        }

        return QaThread::query()
            ->whereIn('certification_id', $certificationIds)
            // 同上。タイトル・投稿者名が漏れないよう、一覧と同じ条件で絞る
            ->wherePublishedCertification()
            ->where('status', QaThreadStatus::Open)
            ->whereDoesntHave('replies')
            ->with(['user', 'certification'])
            ->latest()
            ->limit(5)
            ->get()
            ->values();
    }
}
