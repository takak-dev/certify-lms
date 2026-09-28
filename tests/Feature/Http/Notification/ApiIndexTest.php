<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 通知一覧 API(GET /api/v1/notifications。S-A-05)の検証。
 *
 * ここで固定するのは 5 つ。
 * 1. 未ログインは 401 の JSON で返し、ログイン画面へ転送しないこと(JS が扱えない HTML を返さない)
 * 2. 自分宛の通知しか返さないこと(原典「API が返す対象は認証ユーザー本人の通知のみ」)
 * 3. 1 件の形がポップオーバーの行テンプレートに合っていること(手順2 で決めた JSON の形)
 * 4. 返すのは最新 20 件だけだが、unread_count は全体の未読を数えること
 * 5. ?tab=unread なら未読だけを返し(古い未読も含む。decisions #260)、all / unread 以外は 422 にすること
 *
 * ログインは actingAs() で作る。auth:sanctum は画面からのリクエストを web ガード(セッション)で
 * 認証する設定なので(config/sanctum.php の guard)、web 版のテストと同じログインの作り方で通る。
 */
class ApiIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 通知を 1 件作るヘルパ(web 版 IndexTest と同じ作り方)。
     *
     * DatabaseNotification にはファクトリが無い(Laravel が vendor で持つモデルのため)ので、
     * リレーション経由で直接 create する。$data を渡すと data の中身を差し替えられる。
     *
     * @param array<string, mixed>|null $data
     */
    private function makeNotification(User $for, bool $read = false, string $title = 'テスト通知', ?array $data = null): void
    {
        $for->notifications()->create([
            // 主キーは Laravel の採番に合わせて UUID
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            'data' => $data ?? [
                'notification_type' => 'qa_reply_received',
                'title' => $title,
                'message' => '本文のプレビュー',
                'url' => '/dashboard',
            ],
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_guest_gets_401_json_instead_of_redirect(): void
    {
        // Act: ログインせずに、Accept ヘッダを付けない素の GET で叩く。
        // getJson() は自分で Accept: application/json を付けるので、それを使うと
        // 下の ForceJsonResponse が効いていなくても通ってしまう。ここではその働きを確かめたい
        $response = $this->get(route('api.v1.notifications.index'));

        // Assert: web 版のようにログイン画面へ転送(302)せず、401 を JSON で返す。
        // api グループの ForceJsonResponse(app/Http/Kernel.php:77)が Accept を JSON に固定し、
        // 「JSON を欲しがっている」扱いになるため(app/Http/Middleware/Authenticate.php:17 が転送先を null にする)
        $response->assertUnauthorized();
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_student_gets_only_own_notifications(): void
    {
        // Arrange: 自分に 1 件、他人に 1 件。他人宛が漏れないことを見る
        $me = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $this->makeNotification($me, title: '自分宛の通知');
        $this->makeNotification($other, title: '他人宛の通知');

        // Act
        $response = $this->actingAs($me)->getJson(route('api.v1.notifications.index'));

        // Assert: 自分の 1 件だけ。他人の通知は載らない。
        // assertDontSee() は使わない: JSON は日本語を \uXXXX に変えて返すため、文字列で探すと
        // 他人の通知が混ざっていても見つからず、必ず通ってしまう。JSON として読んで確かめる
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', '自分宛の通知');
        $response->assertJsonMissing(['title' => '他人宛の通知']);
    }

    public function test_coach_gets_own_notifications(): void
    {
        // Arrange: コーチもポップオーバーの対象(原典アクセス制御「受講生 / コーチには表示する」)
        $coach = User::factory()->coach()->create();
        $this->makeNotification($coach, title: 'コーチ宛の通知');

        // Act
        $response = $this->actingAs($coach)->getJson(route('api.v1.notifications.index'));

        // Assert
        $response->assertOk();
        $response->assertJsonPath('data.0.title', 'コーチ宛の通知');
    }

    public function test_admin_is_not_blocked_by_role(): void
    {
        // Arrange: 管理者。ポップオーバーは出さないが、API はロールで絞らない(decisions #259)。
        // 管理者は web の通知一覧で同じ操作が既にできるため、API だけ塞ぐ理由が無い
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->getJson(route('api.v1.notifications.index'));

        // Assert: 403 にはならない。管理者宛の通知は発火しないので中身は空
        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('unread_count', 0);
    }

    public function test_each_item_has_the_shape_the_popover_row_needs(): void
    {
        // Arrange: 未読 1 件。3 分前に届いたことにして「3分前」の文字を確かめる
        $me = User::factory()->student()->inProgress()->create();
        Carbon::setTestNow(now()->subMinutes(3));
        $this->makeNotification($me, title: '回答が届きました');
        Carbon::setTestNow();

        // Act
        $response = $this->actingAs($me)->getJson(route('api.v1.notifications.index'));

        // Assert: 行テンプレートの空欄(notification-popover.blade.php:75-81)を埋める 5 項目がちょうど揃う。
        // assertExactJson ではなく個別に見るのは、id が毎回変わるため
        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [['id', 'title', 'message', 'created_at_human', 'is_unread']],
            'unread_count',
        ]);
        $response->assertJsonPath('data.0.title', '回答が届きました');
        $response->assertJsonPath('data.0.message', '本文のプレビュー');
        $response->assertJsonPath('data.0.created_at_human', '3分前');
        $response->assertJsonPath('data.0.is_unread', true);
        // 遷移先は載せない(既読化 API が決めて返す。NotificationResource の PHPDoc)
        $response->assertJsonMissingPath('data.0.url');
        // ページ送りの links / meta は載せない(ポップオーバーはページ送りを持たない)
        $response->assertJsonMissingPath('links');
        $response->assertJsonMissingPath('meta');
    }

    public function test_missing_title_and_body_preview_fall_back_like_the_web_list(): void
    {
        // Arrange: title を持たず、本文を message ではなく body_preview に持つ通知。
        // web 版の一覧行(notification-row.blade.php:9-10)と同じ既定値になることを見る
        $me = User::factory()->student()->inProgress()->create();
        $this->makeNotification($me, read: true, data: [
            'notification_type' => 'chat_message_received',
            'body_preview' => 'チャットの冒頭',
        ]);

        // Act
        $response = $this->actingAs($me)->getJson(route('api.v1.notifications.index'));

        // Assert
        $response->assertJsonPath('data.0.title', '通知');
        $response->assertJsonPath('data.0.message', 'チャットの冒頭');
        $response->assertJsonPath('data.0.is_unread', false);
    }

    public function test_unread_tab_returns_only_unread_including_older_ones(): void
    {
        // Arrange: 古い順に「未読(古)」→ 既読 20 件 → 「未読(新)」。
        // 全件の最新 20 件には「未読(古)」が入らない並びにして、JS で絞る方式では出せない行を作る
        $me = User::factory()->student()->inProgress()->create();
        $base = now()->startOfMinute();
        Carbon::setTestNow($base->copy()->addMinutes(1));
        $this->makeNotification($me, title: '未読(古)');
        for ($i = 2; $i <= 21; $i++) {
            Carbon::setTestNow($base->copy()->addMinutes($i));
            $this->makeNotification($me, read: true, title: "既読{$i}");
        }
        Carbon::setTestNow($base->copy()->addMinutes(22));
        $this->makeNotification($me, title: '未読(新)');
        Carbon::setTestNow();

        // Act
        $response = $this->actingAs($me)->getJson(route('api.v1.notifications.index', ['tab' => 'unread']));

        // Assert: 未読の 2 件だけが新しい順に並び、行数と unread_count が一致する
        // (未読タブのバッジと中身が食い違わない。decisions #260)
        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.title', '未読(新)');
        $response->assertJsonPath('data.1.title', '未読(古)');
        $response->assertJsonPath('unread_count', 2);
    }

    public function test_unknown_tab_is_rejected_with_422_json(): void
    {
        // Arrange
        $me = User::factory()->student()->inProgress()->create();

        // Act: all / unread 以外の値
        $response = $this->actingAs($me)->getJson(route('api.v1.notifications.index', ['tab' => 'archived']));

        // Assert: web 版と同じ IndexRequest が弾く。API なので画面へ戻さず 422 の JSON
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('tab');
    }

    public function test_returns_latest_20_but_counts_all_unread(): void
    {
        // Arrange: 未読を 21 件、古い順に 1 分ずつずらして作る。最後に作った「通知21」が最新
        $me = User::factory()->student()->inProgress()->create();
        // 基準の時刻を先に 1 つ決めておく(ループ内で now() を使うと、直前に固定した時刻から積み上がる)
        $base = now()->startOfMinute();
        for ($i = 1; $i <= 21; $i++) {
            Carbon::setTestNow($base->copy()->addMinutes($i));
            $this->makeNotification($me, title: "通知{$i}");
        }
        Carbon::setTestNow();

        // Act
        $response = $this->actingAs($me)->getJson(route('api.v1.notifications.index'));

        // Assert: 行は最新 20 件(新しい順)。いちばん古い「通知1」は載らない
        $response->assertJsonCount(20, 'data');
        $response->assertJsonPath('data.0.title', '通知21');
        $response->assertJsonMissing(['title' => '通知1']);
        // 件数は画面の行数(20)ではなく、全体の未読(21)。JS が行を数えても出せない値なのでサーバーが返す
        $response->assertJsonPath('unread_count', 21);
    }
}
