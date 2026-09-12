<?php

declare(strict_types=1);

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Auth\OnboardingController;
use App\Http\Controllers\BrowseController;
use App\Http\Controllers\CertificationCatalogController;
use App\Http\Controllers\CertificationCategoryController;
use App\Http\Controllers\CertificationCoachAssignmentController;
use App\Http\Controllers\CertificationController;
use App\Http\Controllers\ChapterController;
use App\Http\Controllers\ChatRoomController;
use App\Http\Controllers\ContentSearchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\EnrollmentManagementController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LearningHourTargetController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\MeetingQuotaHistoryController;
use App\Http\Controllers\MockExamAnswerController;
use App\Http\Controllers\MockExamCatalogController;
use App\Http\Controllers\MockExamController;
use App\Http\Controllers\MockExamQuestionController;
use App\Http\Controllers\MockExamSessionController;
use App\Http\Controllers\MockExamSessionMonitorController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PartController;
use App\Http\Controllers\QaReplyController;
use App\Http\Controllers\QaThreadController;
use App\Http\Controllers\QuestionCategoryController;
use App\Http\Controllers\QuizHistoryController;
use App\Http\Controllers\QuizStatsController;
use App\Http\Controllers\ReceiveCertificateController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SectionImageController;
use App\Http\Controllers\SectionProgressController;
use App\Http\Controllers\SectionQuestionAnswerController;
use App\Http\Controllers\SectionQuestionController;
use App\Http\Controllers\SectionQuizController;
use App\Http\Controllers\SectionQuizResultController;
use App\Http\Controllers\Settings\AvailabilityController as SettingsAvailabilityController;
use App\Http\Controllers\Settings\AvatarController as SettingsAvatarController;
use App\Http\Controllers\Settings\PasswordController as SettingsPasswordController;
use App\Http\Controllers\Settings\ProfileController as SettingsProfileController;
use App\Http\Controllers\Settings\SettingsDefaultEnrollmentController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WeakDrillController;
use App\Http\Controllers\WeakDrillResultController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard.index')
        : redirect('/login');
});

// ============================================================
// 認証フロー(オンボーディング: 招待 URL 経由の初回登録)
// ============================================================
// signed middleware は store のみに適用し、show は Controller 内で署名検証して invalid 時に friendly view を返す
Route::get('/onboarding/{invitation}', [OnboardingController::class, 'show'])
    ->name('onboarding.show');
Route::post('/onboarding/{invitation}', [OnboardingController::class, 'store'])
    ->middleware('signed')
    ->name('onboarding.store');

// ============================================================
// 認証後の全ロール共通ルート
// ============================================================
Route::middleware('auth')->group(function () {
    // ダッシュボード
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');

    // 受講登録(3 ロール共有: student=自分のみ / coach=担当範囲 / admin=全件)。
    // 認可は EnrollmentPolicy::viewAny / view で 3 ロール対応済。閲覧範囲は EnrollmentController で
    // ロール別 eager-load + Blade の @can / @if で UI を出し分ける。
    Route::get('enrollments', [EnrollmentController::class, 'index'])->name('enrollments.index');
    Route::get('enrollments/{enrollment}', [EnrollmentController::class, 'show'])
        ->withTrashed()
        ->name('enrollments.show');
});

// ============================================================
// 設定ルート(本人のプロフィール / パスワード / アバター)
//
// ⚠️ ミドルウェアは auth のみ。role: も active-learning も付けない。
//    S-B-06 の要件が「全ロールが共通の設定画面を使う」「修了済(graduated)の受講生も使える」ため、
//    ロール判定や学習中判定を挟むと要件違反になる(下の受講生専用 settings グループとは別物)。
//
//    全ルートがパラメータを持たない(/settings/profile など)ので、更新対象は常にログイン中の本人になる。
//    「自分以外のユーザーの情報は更新できない」はルートの形が保証しており、Policy は設けない。
// ============================================================
Route::middleware('auth')
    ->prefix('settings')
    ->name('settings.')
    ->group(function () {
        // プロフィール設定画面(タブ切替のホスト)。サイドバー 3 枚とトップバーが Route::has でこの名前を見ている。
        Route::get('profile', [SettingsProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('profile', [SettingsProfileController::class, 'update'])->name('profile.update');

        // パスワード変更。Fortify 既定の PUT /user/password は登録せず、ここで受けて
        // App\Actions\Fortify\UpdateUserPassword に委譲する(config/fortify.php のコメントの指示どおり)。
        Route::put('password', [SettingsPasswordController::class, 'update'])->name('password.update');

        // アバター画像。アップロードと削除で 1 つの URL を HTTP メソッドで分ける。
        Route::post('avatar', [SettingsAvatarController::class, 'store'])->name('avatar.store');
        Route::delete('avatar', [SettingsAvatarController::class, 'destroy'])->name('avatar.destroy');
    });

// ============================================================
// 受講生専用ルート(受講中ステータスのみ通過、卒業ステータスはロック)
// ============================================================
Route::middleware(['auth', 'role:student', 'active-learning'])->group(function () {
    // 資格カタログ(受講生視点の閲覧)
    Route::get('certifications', [CertificationCatalogController::class, 'index'])
        ->name('certifications.index');
    Route::get('certifications/{certification}', [CertificationCatalogController::class, 'show'])
        ->name('certifications.show');

    // 教材検索(登録資格内の Published Section を全文検索)
    Route::get('contents/search', [ContentSearchController::class, 'search'])
        ->name('contents.search');

    // 受講登録 — 自己登録 / 受講解除 / failed からの再挑戦 / 目標受験日設定(index / show は全ロール共有 group 側に定義)
    Route::post('enrollments', [EnrollmentController::class, 'store'])->name('enrollments.store');
    Route::delete('enrollments/{enrollment}', [EnrollmentController::class, 'destroy'])->name('enrollments.destroy');
    Route::post('enrollments/{enrollment}/resume', [EnrollmentController::class, 'resume'])->name('enrollments.resume');
    Route::patch('enrollments/{enrollment}/exam-date', [EnrollmentController::class, 'updateExamDate'])->name('enrollments.updateExamDate');

    // 修了証受領(受講生自己発火、graduated は active-learning でブロックされるため新規受領不可)
    Route::post('enrollments/{enrollment}/receive-certificate', [ReceiveCertificateController::class, 'store'])
        ->name('enrollments.receiveCertificate');
});

// ============================================================
// 受講生専用 設定ルート(デフォルト資格の永続変更)
//
// ⚠️ このグループは「受講中(in_progress)の受講生だけが使う設定」専用。
//    active-learning が付いているため graduated / withdrawn / invited は 403 になり、
//    role:student のため coach / admin も 403 になる。
//    プロフィール / パスワード / アバターのように「修了後も使える」「全ロールが使う」設定を
//    settings プレフィックスで足したくなっても、このグループには追加しないこと。
//    その場合は auth のみのグループ(本ファイル冒頭の「認証後の全ロール共通ルート」)側に
//    別グループを作る。認可はロールではなく Policy で行う。
// ============================================================
Route::middleware(['auth', 'role:student', 'active-learning'])
    ->prefix('settings')
    ->name('settings.')
    ->group(function () {
        Route::put('default-enrollment/{enrollment}', [SettingsDefaultEnrollmentController::class, 'update'])
            ->name('default-enrollment.update');
    });

// ============================================================
// 受講生専用ルート — 教材閲覧 / 読了マーク / 学習時間目標
// ============================================================
Route::middleware(['auth', 'role:student', 'active-learning'])
    ->prefix('learning')
    ->name('learning.')
    ->group(function () {
        // 教材ブラウジング
        Route::get('/', [BrowseController::class, 'index'])
            ->middleware('resolve-default-enrollment:learning.enrollments.show')
            ->name('index');
        Route::get('enrollments/{enrollment}', [BrowseController::class, 'showEnrollment'])
            ->name('enrollments.show');
        Route::get('parts/{part}', [BrowseController::class, 'showPart'])->name('parts.show');
        Route::get('chapters/{chapter}', [BrowseController::class, 'showChapter'])->name('chapters.show');
        Route::get('sections/{section}', [BrowseController::class, 'showSection'])
            ->middleware('start-learning-session')
            ->name('sections.show');

        // Section 読了マーク
        Route::post('sections/{section}/read', [SectionProgressController::class, 'markRead'])
            ->name('sections.markRead');
        Route::delete('sections/{section}/read', [SectionProgressController::class, 'unmarkRead'])
            ->name('sections.unmarkRead');

        // 学習時間目標
        Route::get('enrollments/{enrollment}/hour-target', [LearningHourTargetController::class, 'show'])
            ->name('hourTarget.show');
        Route::put('enrollments/{enrollment}/hour-target', [LearningHourTargetController::class, 'upsert'])
            ->name('hourTarget.upsert');
        Route::delete('enrollments/{enrollment}/hour-target', [LearningHourTargetController::class, 'destroy'])
            ->name('hourTarget.destroy');
    });

// ============================================================
// admin 専用ルート
// ============================================================
Route::middleware(['auth', 'role:admin'])->prefix('admin')->group(function () {
    // ユーザー管理
    Route::get('users', [UserController::class, 'index'])->name('admin.users.index');
    Route::get('users/{user}', [UserController::class, 'show'])
        ->withTrashed()
        ->name('admin.users.show');
    Route::post('users/{user}/withdraw', [UserController::class, 'withdraw'])->name('admin.users.withdraw');
    Route::post('users/{user}/extend-course', [UserController::class, 'extendCourse'])->name('admin.users.extendCourse');
    Route::post('users/{user}/grant-meeting-quota', [UserController::class, 'grantMeetingQuota'])->name('admin.users.grantMeetingQuota');

    // 招待管理
    Route::post('invitations', [InvitationController::class, 'store'])->name('admin.invitations.store');
    Route::post('users/{user}/resend-invitation', [InvitationController::class, 'resend'])->name('admin.invitations.resend');
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('admin.invitations.destroy');

    // 資格マスタ管理(資格本体の CRUD + 状態遷移、admin のみ)
    Route::resource('certifications', CertificationController::class)
        ->except(['index', 'show'])
        ->parameters(['certifications' => 'certification'])
        ->names('admin.certifications');
    Route::post('certifications/{certification}/publish', [CertificationController::class, 'publish'])
        ->name('admin.certifications.publish');
    Route::post('certifications/{certification}/unpublish', [CertificationController::class, 'unpublish'])
        ->name('admin.certifications.unpublish');
    Route::post('certifications/{certification}/archive', [CertificationController::class, 'archive'])
        ->name('admin.certifications.archive');

    // 担当コーチ割当(資格 ↔ コーチ、admin のみ)
    Route::post('certifications/{certification}/coaches/{coach}', [CertificationCoachAssignmentController::class, 'attach'])
        ->name('admin.certifications.coaches.attach');
    Route::delete('certifications/{certification}/coaches/{coach}', [CertificationCoachAssignmentController::class, 'detach'])
        ->name('admin.certifications.coaches.detach');

    // カテゴリ管理(資格分類マスタ)
    Route::resource('certification-categories', CertificationCategoryController::class)
        ->parameters(['certification-categories' => 'category'])
        ->except(['show', 'create', 'edit'])
        ->names('admin.certification-categories');

    // 受講登録管理 — 試験日変更 / 手動学習中止のみ admin 専用(一覧 / 詳細は全ロール共有 group 側に定義)
    Route::patch('enrollments/{enrollment}/exam-date', [EnrollmentManagementController::class, 'updateExamDate'])
        ->name('admin.enrollments.updateExamDate');
    Route::post('enrollments/{enrollment}/fail', [EnrollmentManagementController::class, 'fail'])
        ->name('admin.enrollments.fail');

    // お知らせ配信 — 配信は不可逆(再配信 / 編集 / 取消なし)。
    // edit / update / destroy を作らないことで、UI からも API からも書き換えられなくする
    // (原典「お知らせには編集 / 削除 / 再配信のルートを設けない」)。
    Route::resource('announcements', AnnouncementController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->names('admin.announcements');
});

// ============================================================
// admin + コーチ共有ルート(資格マスタ閲覧 / 教材管理: コーチは担当資格のみ Policy + scope で絞り込み)
// ============================================================
Route::middleware(['auth', 'role:admin,coach'])->prefix('admin')->group(function () {
    // 資格マスタ閲覧 (admin = 全件 / coach = 担当資格のみ、Certification::scopeForUser で絞込)
    Route::resource('certifications', CertificationController::class)
        ->only(['index', 'show'])
        ->parameters(['certifications' => 'certification'])
        ->names('admin.certifications');

    // 教材管理 — Part: 一覧 / 新規作成 / 並び替え
    Route::get('certifications/{certification}/parts', [PartController::class, 'index'])
        ->name('admin.certifications.parts.index');
    Route::post('certifications/{certification}/parts', [PartController::class, 'store'])
        ->name('admin.certifications.parts.store');
    Route::patch('certifications/{certification}/parts/reorder', [PartController::class, 'reorder'])
        ->name('admin.certifications.parts.reorder');

    // 教材管理 — Part: 詳細 / 更新 / 削除 / 公開遷移 + 配下 Chapter の作成・並び替え
    Route::get('parts/{part}', [PartController::class, 'show'])->name('admin.parts.show');
    Route::patch('parts/{part}', [PartController::class, 'update'])->name('admin.parts.update');
    Route::delete('parts/{part}', [PartController::class, 'destroy'])->name('admin.parts.destroy');
    Route::post('parts/{part}/publish', [PartController::class, 'publish'])->name('admin.parts.publish');
    Route::post('parts/{part}/unpublish', [PartController::class, 'unpublish'])->name('admin.parts.unpublish');
    Route::post('parts/{part}/chapters', [ChapterController::class, 'store'])->name('admin.parts.chapters.store');
    Route::patch('parts/{part}/chapters/reorder', [ChapterController::class, 'reorder'])
        ->name('admin.parts.chapters.reorder');

    // 教材管理 — Chapter: 詳細 / 更新 / 削除 / 公開遷移 + 配下 Section の作成・並び替え
    Route::get('chapters/{chapter}', [ChapterController::class, 'show'])->name('admin.chapters.show');
    Route::patch('chapters/{chapter}', [ChapterController::class, 'update'])->name('admin.chapters.update');
    Route::delete('chapters/{chapter}', [ChapterController::class, 'destroy'])->name('admin.chapters.destroy');
    Route::post('chapters/{chapter}/publish', [ChapterController::class, 'publish'])->name('admin.chapters.publish');
    Route::post('chapters/{chapter}/unpublish', [ChapterController::class, 'unpublish'])->name('admin.chapters.unpublish');
    Route::post('chapters/{chapter}/sections', [SectionController::class, 'store'])
        ->name('admin.chapters.sections.store');
    Route::patch('chapters/{chapter}/sections/reorder', [SectionController::class, 'reorder'])
        ->name('admin.chapters.sections.reorder');

    // 教材管理 — Section: 詳細 / 更新 / 削除 / 公開遷移 / Markdown プレビュー / 画像アップロード
    Route::get('sections/{section}', [SectionController::class, 'show'])->name('admin.sections.show');
    Route::patch('sections/{section}', [SectionController::class, 'update'])->name('admin.sections.update');
    Route::delete('sections/{section}', [SectionController::class, 'destroy'])->name('admin.sections.destroy');
    Route::post('sections/{section}/publish', [SectionController::class, 'publish'])->name('admin.sections.publish');
    Route::post('sections/{section}/unpublish', [SectionController::class, 'unpublish'])->name('admin.sections.unpublish');
    Route::post('sections/{section}/preview', [SectionController::class, 'preview'])->name('admin.sections.preview');
    Route::post('sections/{section}/images', [SectionImageController::class, 'store'])
        ->name('admin.sections.images.store');

    // 教材管理 — Section 内画像の削除
    Route::delete('section-images/{image}', [SectionImageController::class, 'destroy'])
        ->name('admin.section-images.destroy');

    // 演習管理 — 出題分野マスタ
    Route::get('certifications/{certification}/question-categories', [QuestionCategoryController::class, 'index'])
        ->name('admin.certifications.question-categories.index');
    Route::post('certifications/{certification}/question-categories', [QuestionCategoryController::class, 'store'])
        ->name('admin.certifications.question-categories.store');
    Route::patch('question-categories/{category}', [QuestionCategoryController::class, 'update'])
        ->name('admin.question-categories.update');
    Route::delete('question-categories/{category}', [QuestionCategoryController::class, 'destroy'])
        ->name('admin.question-categories.destroy');

    // 模試管理 — 模試マスタ CRUD + 公開状態遷移
    Route::resource('mock-exams', MockExamController::class)
        ->parameters(['mock-exams' => 'mockExam'])
        ->names('admin.mock-exams');
    Route::post('mock-exams/{mockExam}/publish', [MockExamController::class, 'publish'])
        ->name('admin.mock-exams.publish');
    Route::post('mock-exams/{mockExam}/unpublish', [MockExamController::class, 'unpublish'])
        ->name('admin.mock-exams.unpublish');

    // 模試管理 — 模試問題 CRUD(模試マスタの子リソース、shallow)
    Route::get('mock-exams/{mockExam}/questions', [MockExamQuestionController::class, 'index'])
        ->name('admin.mock-exams.questions.index');
    Route::get('mock-exams/{mockExam}/questions/create', [MockExamQuestionController::class, 'create'])
        ->name('admin.mock-exams.questions.create');
    Route::post('mock-exams/{mockExam}/questions', [MockExamQuestionController::class, 'store'])
        ->name('admin.mock-exams.questions.store');
    Route::get('mock-exam-questions/{question}', [MockExamQuestionController::class, 'show'])
        ->name('admin.mock-exam-questions.show');
    Route::get('mock-exam-questions/{question}/edit', [MockExamQuestionController::class, 'edit'])
        ->name('admin.mock-exam-questions.edit');
    Route::put('mock-exam-questions/{question}', [MockExamQuestionController::class, 'update'])
        ->name('admin.mock-exam-questions.update');
    Route::delete('mock-exam-questions/{question}', [MockExamQuestionController::class, 'destroy'])
        ->name('admin.mock-exam-questions.destroy');

    // 模試管理 — 受講生セッション閲覧(coach は担当資格のみ)
    Route::get('mock-exam-sessions', [MockExamSessionMonitorController::class, 'index'])
        ->name('admin.mock-exam-sessions.index');
    Route::get('mock-exam-sessions/{session}', [MockExamSessionMonitorController::class, 'show'])
        ->name('admin.mock-exam-sessions.show');

    // 演習管理 — Section 紐づき演習問題: 一覧 / 作成 / 詳細・編集 / 公開遷移(Section 経由でのみアクセス)
    Route::get('sections/{section}/questions', [SectionQuestionController::class, 'index'])
        ->name('admin.sections.questions.index');
    Route::get('sections/{section}/questions/create', [SectionQuestionController::class, 'create'])
        ->name('admin.sections.questions.create');
    Route::post('sections/{section}/questions', [SectionQuestionController::class, 'store'])
        ->name('admin.sections.questions.store');
    Route::get('section-questions/{sectionQuestion}', [SectionQuestionController::class, 'show'])
        ->name('admin.section-questions.show');
    Route::patch('section-questions/{sectionQuestion}', [SectionQuestionController::class, 'update'])
        ->name('admin.section-questions.update');
    Route::delete('section-questions/{sectionQuestion}', [SectionQuestionController::class, 'destroy'])
        ->name('admin.section-questions.destroy');
    Route::post('section-questions/{sectionQuestion}/publish', [SectionQuestionController::class, 'publish'])
        ->name('admin.section-questions.publish');
    Route::post('section-questions/{sectionQuestion}/unpublish', [SectionQuestionController::class, 'unpublish'])
        ->name('admin.section-questions.unpublish');
});

// ============================================================
// 受講生専用ルート — 模試カタログ(/learning 配下に集約、default 資格は middleware で解決)
// ============================================================
Route::middleware(['auth', 'role:student', 'active-learning'])
    ->prefix('learning/enrollments/{enrollment}')
    ->name('mock-exam.')
    ->group(function () {
        Route::get('mock-exams', [MockExamCatalogController::class, 'index'])->name('catalog.index');
        Route::get('mock-exams/{mockExam}', [MockExamCatalogController::class, 'show'])->name('catalog.show');
        Route::post('mock-exams/{mockExam}/sessions', [MockExamSessionController::class, 'store'])
            ->name('sessions.store');
    });

// /mock-exams 直接アクセスは default 資格へ自動 redirect(default 未設定で複数 Enrollment 時のみフォールバック画面)
Route::middleware(['auth', 'role:student', 'active-learning'])->group(function () {
    Route::get('mock-exams', [MockExamCatalogController::class, 'fallbackIndex'])
        ->middleware('resolve-default-enrollment:mock-exam.catalog.index')
        ->name('mock-exam.fallback.index');

    // 受験セッション操作群(セッション ID 直接参照、enrollment 不要)
    Route::get('mock-exam-sessions', [MockExamSessionController::class, 'index'])->name('mock-exam-sessions.index');
    Route::get('mock-exam-sessions/{session}', [MockExamSessionController::class, 'show'])
        ->name('mock-exam-sessions.show');
    Route::post('mock-exam-sessions/{session}/start', [MockExamSessionController::class, 'start'])
        ->name('mock-exam-sessions.start');
    Route::post('mock-exam-sessions/{session}/submit', [MockExamSessionController::class, 'submit'])
        ->name('mock-exam-sessions.submit');
    Route::delete('mock-exam-sessions/{session}', [MockExamSessionController::class, 'destroy'])
        ->name('mock-exam-sessions.destroy');
    Route::patch('mock-exam-sessions/{session}/answers', [MockExamAnswerController::class, 'update'])
        ->name('mock-exam-sessions.answers.update');
});

// ============================================================
// 受講生専用ルート — Section 紐づき問題演習 / 苦手分野ドリル / 解答履歴
// ============================================================
Route::middleware(['auth', 'role:student', 'active-learning'])
    ->prefix('quiz')
    ->name('quiz.')
    ->group(function () {
        // Section 経路
        Route::get('sections/{section}', [SectionQuizController::class, 'show'])
            ->name('sections.show');
        Route::get('sections/{section}/questions/{question}', [SectionQuizController::class, 'showQuestion'])
            ->name('sections.question');
        Route::get('sections/{section}/questions/{question}/result/{answer}', [SectionQuizResultController::class, 'show'])
            ->name('sections.result');

        // 苦手分野ドリル経路
        Route::get('drills/{enrollment}', [WeakDrillController::class, 'index'])
            ->name('drills.index');
        Route::get('drills/{enrollment}/categories/{questionCategory}', [WeakDrillController::class, 'showCategory'])
            ->name('drills.category');
        Route::get('drills/{enrollment}/categories/{questionCategory}/questions/{question}', [WeakDrillController::class, 'showQuestion'])
            ->name('drills.question');
        Route::get('drills/{enrollment}/categories/{questionCategory}/questions/{question}/result/{answer}', [WeakDrillResultController::class, 'show'])
            ->name('drills.result');

        // 解答送信(両経路共通エンドポイント、source 値で結果画面を分岐)
        Route::post('questions/{question}/answer', [SectionQuestionAnswerController::class, 'store'])
            ->name('answers.store');

        // 履歴・サマリ
        Route::get('history/{enrollment}', [QuizHistoryController::class, 'index'])
            ->name('history.index');
        Route::get('stats/{enrollment}', [QuizStatsController::class, 'index'])
            ->name('stats.index');
    });

// ============================================================
// 受講生専用ルート — 面談予約 (履歴一覧は資格横断 / 予約画面は default 資格に解決)
// ============================================================
Route::middleware(['auth', 'role:student', 'active-learning'])->group(function () {
    // 履歴一覧: 資格横断、Switcher 適用なし
    Route::get('meetings', [MeetingController::class, 'index'])->name('meetings.index');

    // 予約画面エントリ: default 資格があれば canonical URL へ redirect、無ければ empty-state 表示
    Route::get('meetings/create', [MeetingController::class, 'createFallback'])
        ->middleware('resolve-default-enrollment:meetings.create')
        ->name('meetings.fallback.create');

    // 予約画面 canonical: URL に Enrollment を含む
    Route::prefix('enrollments/{enrollment}')->group(function () {
        Route::get('meetings/create', [MeetingController::class, 'create'])->name('meetings.create');
        Route::get('meetings/availability', [MeetingController::class, 'fetchAvailability'])->name('meetings.availability');
        Route::post('meetings', [MeetingController::class, 'store'])->name('meetings.store');
    });
});

// ============================================================
// 当事者共通ルート — 面談予約の詳細 / キャンセル
// ============================================================
Route::middleware('auth')->group(function () {
    Route::get('meetings/{meeting}', [MeetingController::class, 'show'])->name('meetings.show');
    Route::post('meetings/{meeting}/cancel', [MeetingController::class, 'cancel'])->name('meetings.cancel');
});

// ============================================================
// 受講生・コーチ共有 — chat (グループルーム閲覧 / メッセージ送信)
// ============================================================
Route::middleware(['auth', 'role:student,coach', 'active-learning'])->group(function () {
    Route::get('chat-rooms', [ChatRoomController::class, 'index'])
        ->name('chat.index');
    Route::get('chat-rooms/{room}', [ChatRoomController::class, 'show'])
        ->name('chat.show');
    Route::post('chat-rooms/{room}/messages', [ChatRoomController::class, 'storeMessage'])
        ->name('chat.storeMessage');
});

// ============================================================
// コーチ専用 — chat 未読あり一覧
// ============================================================
Route::middleware(['auth', 'role:coach', 'active-learning'])->group(function () {
    Route::get('coach/chat-rooms', [ChatRoomController::class, 'indexAsCoach'])
        ->name('coach.chat.index');
});

// ============================================================
// 管理者専用 — chat 監査閲覧
// ============================================================
Route::middleware(['auth', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('chat-rooms', [ChatRoomController::class, 'index'])
        ->name('admin.chat-rooms.index');
    Route::get('chat-rooms/{room}', [ChatRoomController::class, 'show'])
        ->name('admin.chat-rooms.show');
});

// ============================================================
// コーチ専用ルート — 担当資格受講生管理 / 面談管理 / メモ記録
// ============================================================
Route::middleware(['auth', 'role:coach'])->prefix('coach')->name('coach.')->group(function () {
    // 担当受講生の一覧 / 詳細は全ロール共有 `enrollments.index` / `enrollments.show` 側に定義(認可は Policy で範囲を絞る)

    // 面談管理
    Route::get('meetings', [MeetingController::class, 'indexAsCoach'])->name('meetings.index');
    Route::put('meetings/{meeting}/memo', [MeetingController::class, 'upsertMemo'])->name('meetings.memo');
});

// ============================================================
// コーチ専用ルート — 面談可能時間枠の編集
// ============================================================
Route::middleware(['auth', 'role:coach'])
    ->prefix('settings/availability')
    ->name('settings.availability.')
    ->group(function () {
        Route::get('/', [SettingsAvailabilityController::class, 'index'])->name('index');
        Route::post('/', [SettingsAvailabilityController::class, 'store'])->name('store');
        Route::patch('{availability}', [SettingsAvailabilityController::class, 'update'])->name('update');
        Route::delete('{availability}', [SettingsAvailabilityController::class, 'destroy'])->name('destroy');
    });

// ============================================================
// 受講生専用ルート(受講中=in_progress のみ通過)
// ============================================================
Route::middleware(['auth', 'role:student', 'active-learning'])->prefix('meeting-quota')->name('meeting-quota.')->group(function () {
    // 面談回数履歴
    Route::get('history', [MeetingQuotaHistoryController::class, 'index'])->name('history');
});

// ============================================================
// 受講生・コーチ共有 — 質問掲示板(公開)
// ============================================================
// 原典「受講中の受講生・コーチのみアクセスできる」に従い active-learning を付ける。
// 管理者は本グループに含めない(モデレーションは admin.qa-board.* を使う)。
Route::middleware(['auth', 'role:student,coach', 'active-learning'])->group(function () {
    Route::get('qa-board', [QaThreadController::class, 'index'])
        ->name('qa-board.index');
    // 投稿は受講生のみ(QaThreadPolicy::create で判定する)
    Route::get('qa-board/create', [QaThreadController::class, 'create'])
        ->name('qa-board.create');
    Route::post('qa-board', [QaThreadController::class, 'store'])
        ->name('qa-board.store');
    Route::get('qa-board/{thread}', [QaThreadController::class, 'show'])
        ->name('qa-board.show');
    // 編集は投稿者本人のみ。資格は変更できない(decisions #65)
    Route::get('qa-board/{thread}/edit', [QaThreadController::class, 'edit'])
        ->name('qa-board.edit');
    Route::patch('qa-board/{thread}', [QaThreadController::class, 'update'])
        ->name('qa-board.update');
    // 削除は投稿者本人のみ。回答が付いていれば DestroyAction が 409(decisions #37)
    Route::delete('qa-board/{thread}', [QaThreadController::class, 'destroy'])
        ->name('qa-board.destroy');
    // 解決マークの切替。投稿者本人のみ(QaThreadPolicy::resolve / unresolve)
    Route::post('qa-board/{thread}/resolve', [QaThreadController::class, 'resolve'])
        ->name('qa-board.resolve');
    Route::post('qa-board/{thread}/unresolve', [QaThreadController::class, 'unresolve'])
        ->name('qa-board.unresolve');
    // 回答の投稿。管理者は QaReplyPolicy::create で弾く
    Route::post('qa-board/{thread}/replies', [QaReplyController::class, 'store'])
        ->name('qa-board.replies.store');
    // 回答の編集・削除は投稿者本人のみ(QaReplyPolicy::update / delete)
    Route::get('qa-board/{thread}/replies/{reply}/edit', [QaReplyController::class, 'edit'])
        ->name('qa-board.replies.edit');
    Route::patch('qa-board/{thread}/replies/{reply}', [QaReplyController::class, 'update'])
        ->name('qa-board.replies.update');
    Route::delete('qa-board/{thread}/replies/{reply}', [QaReplyController::class, 'destroy'])
        ->name('qa-board.replies.destroy');
});

// ============================================================
// 管理者専用 — 質問掲示板モデレーション
// ============================================================
// 公開停止中の資格を含む全スレッドを横断閲覧し、不適切な投稿を削除する。
// 画面は公開側と同じ Blade を共用し、`request()->routeIs('admin.*')` で切り替わる。
// active-learning は付けない(管理者は受講中の概念を持たない。既存の admin ルート群と同じ)。
Route::middleware(['auth', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('qa-board', [QaThreadController::class, 'index'])
        ->name('admin.qa-board.index');
    Route::get('qa-board/{thread}', [QaThreadController::class, 'show'])
        ->name('admin.qa-board.show');
    Route::delete('qa-board/{thread}', [QaThreadController::class, 'destroy'])
        ->name('admin.qa-board.destroy');
    Route::delete('qa-board/{thread}/replies/{reply}', [QaReplyController::class, 'destroy'])
        ->name('admin.qa-board.replies.destroy');
});

// ============================================================
// 全ロール共通 — 通知(一覧・詳細)
// ============================================================
// S-B-04 原典の HTTP 表は 3 本とも認可欄が「認証ユーザー」。role: も active-learning も付けない。
// 詳細(notifications.show)は S-B-08 で追加した 4 本目で、認可欄は同じ「認証ユーザー(自分宛のみ)」。
// ・修了者も過去に受け取った通知を見られる必要がある。EnsureActiveLearning の PHPDoc(:16)が
//   「プロフィール / 修了証 PDF DL / 通知一覧は引き続き利用可能」と通知一覧を名指しで除外している
// ・管理者宛の通知は発火しない(チケットのスコープ外)ため中身は空になるが、
//   サイドバー(sidebar-admin.blade.php:22)に項目があるため画面自体は開ける
Route::middleware('auth')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');
    // 一覧の行クリックがこれを叩く。既読化したあと、その通知が指す業務画面へリダイレクトする。
    // 他人の通知は NotificationPolicy::markAsRead が 403 で弾く
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
        ->name('notifications.markAsRead');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead'])
        ->name('notifications.markAllAsRead');
    // 通知詳細(S-B-08 で追加)。遷移先の業務画面を持たない通知——運営お知らせ——の全文をここで読む。
    // 開けるのは宛先本人だけ(NotificationPolicy::view)。ロールでは絞らない
    Route::get('notifications/{notification}', [NotificationController::class, 'show'])
        ->name('notifications.show');
});

// ============================================================
// 開発専用: 共通コンポーネントショーケース(APP_ENV=local のみ表示)
// ============================================================
if (app()->environment('local')) {
    Route::get('/_dev/components', function () {
        return view('_dev.components');
    })->name('_dev.components');
}
