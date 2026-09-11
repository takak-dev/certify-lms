<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Announcement\StoreRequest;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\UseCases\Announcement\IndexAction;
use App\UseCases\Announcement\ShowAction;
use App\UseCases\Announcement\StoreAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * admin 用のお知らせ配信 Controller。配信履歴の閲覧と新規配信を提供する。
 *
 * 配信は不可逆(再配信 / 編集 / 取消なし)のため edit / update / destroy を持たない。
 * ルート側も only(['index', 'create', 'store', 'show']) で 4 本に絞ってある(routes/web.php:211)。
 */
class AnnouncementController extends Controller
{
    /**
     * 配信履歴の一覧。新しい配信が上に来る。
     */
    public function index(IndexAction $action): View
    {
        $this->authorize('viewAny', Announcement::class);

        return view('announcement.management.index', [
            'announcements' => $action(),
        ]);
    }

    /**
     * 配信作成フォーム。
     *
     * 画面は「対象資格」と「対象受講生」の 2 つのセレクトを常時表示するため
     * (target-fields.blade.php:46-62)、どちらの候補も渡す。
     */
    public function create(): View
    {
        $this->authorize('create', Announcement::class);

        return view('announcement.management.create', [
            // 資格は状態で絞らない。配信対象を決めるのは受講登録であって資格の公開状態ではないため
            // (手本: MockExamController.php:64 も同じ絞り込み方)
            'certifications' => Certification::query()->orderBy('name')->get(),
            // 候補は受講中の受講生のみ(decisions #48)。配信集合の解決も同じスコープを通る
            'students' => User::query()->inProgressStudents()->orderBy('name')->get(),
        ]);
    }

    /**
     * 配信を実行する。認可は StoreRequest::authorize() が担当する。
     *
     * 遷移先は詳細画面(_共通ルール.md §1「作成 → 詳細画面があれば show」)。
     * ⭐ これが誤配信に気づく導線になる(decisions #88)。詳細画面は配信対象・対象名・配信件数を
     * 並べて表示するため、ラジオの押し間違いは配信直後に目に入る。
     * フラッシュにも件数を入れるのは、原典が配信件数の確認を要件の中心に据えているため。
     */
    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $announcement = $action($request->user(), $request->validated());

        return redirect()
            ->route('admin.announcements.show', $announcement)
            ->with('success', sprintf(
                '%sへお知らせを配信しました。（%d 件）',
                $this->targetLabel($announcement),
                $announcement->dispatched_count,
            ));
    }

    /**
     * 配信履歴の詳細。配信件数 / 配信時刻 / 配信者を確認する監査用の画面。
     */
    public function show(Announcement $announcement, ShowAction $action): View
    {
        $this->authorize('view', $announcement);

        return view('announcement.management.show', [
            'announcement' => $action($announcement),
        ]);
    }

    /**
     * フラッシュメッセージに出す配信先の表現。
     * 「全受講生」だけでは足りず、資格名・受講生名まで出さないと取り違えに気づけない。
     */
    private function targetLabel(Announcement $announcement): string
    {
        $label = $announcement->target_type->label();
        $name = $announcement->targetCertification?->name ?? $announcement->targetUser?->name;

        return $name === null ? $label : $label.'（'.$name.'）';
    }
}
