<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Settings;

use App\Models\GoogleCredential;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\GoogleOAuthStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Google カレンダー連携の OAuth 動線を検証する(S-A-01)。
 *
 * ここで守るのは原典の 2 条。
 *  ① 「連携処理が正規の本人によるものか検証し、なりすましや不正な連携を拒否する」（共通の振る舞い）
 *  ② 「コーチのみ」（インターフェース表の認可欄）
 *
 * ⚠️ この 3 ルートは Policy も FormRequest の authorize() も持たず、`role:coach` middleware と
 *    「$request->user() しか見ない」ことだけで守られている。そこが壊れても誰も気づけないので、
 *    ここで固定する（CLAUDE.md 再発防止）。
 *
 * ⚠️ Google への通信は差し替える。実通信はテストをネットワークとトークンの状態に依存させる。
 */
class GoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * セッションキーは Service の public 定数を参照する。
     *
     * ⚠️ ここで文字列を書き写すと、Service 側だけキーを変えてもテストは緑のまま通る
     *    （別キーに入るだけで state 不一致の分岐に落ち、`assertSessionHas('error')` が成立してしまう）。
     */
    private const SESSION_STATE = GoogleOAuthStateService::SESSION_STATE;

    private const SESSION_REDIRECT_PATH = GoogleOAuthStateService::SESSION_REDIRECT_PATH;

    /**
     * .env が設定済みの状態を作る。
     *
     * テスト環境には GOOGLE_* が無いため、これを呼ばないと isConfigured() が false になり
     * 「設定されていません」の分岐に落ちる。
     */
    private function configureGoogle(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect_uri' => 'http://localhost/settings/google-calendar/callback',
        ]);
    }

    // ================================================================
    // 認可（原典: コーチのみ）
    // ================================================================

    /**
     * コーチ以外は 3 ルートとも 403。
     *
     * ⚠️ ルート定義から `role:coach` を落としても、この 1 本が無ければ全テストが緑のまま。
     */
    public function test_non_coach_users_are_forbidden_on_every_route(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $admin = User::factory()->admin()->create();

        foreach ([$student, $admin] as $user) {
            $this->actingAs($user)->get(route('settings.google-calendar.redirect'))->assertForbidden();
            $this->actingAs($user)->get(route('settings.google-calendar.callback'))->assertForbidden();
            $this->actingAs($user)->delete(route('settings.google-calendar.destroy'))->assertForbidden();
        }
    }

    /** 未ログインはログイン画面へ。 */
    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('settings.google-calendar.redirect'))->assertRedirect(route('login'));
    }

    // ================================================================
    // 連携の開始
    // ================================================================

    /** 連携を開始すると Google の同意画面へ送られ、state がセッションに預けられる。 */
    public function test_redirect_sends_the_coach_to_google_and_stores_the_state(): void
    {
        // Arrange
        $this->configureGoogle();
        $coach = User::factory()->coach()->create();

        // Act
        $response = $this->actingAs($coach)->get(route('settings.google-calendar.redirect'));

        // Assert: 外部（Google）へのリダイレクト
        $response->assertRedirectContains('accounts.google.com');

        // ⭐ Assert: 利用者に求める権限と、トークンの受け取り方を固定する。
        //    ⚠️ これが無いと SCOPES を広い `calendar`（カレンダーの共有設定・完全削除まで含む）に
        //       書き換えても全テストが緑のまま。**実際の利用者から受け取る権限が静かに広がる。**
        $response->assertRedirectContains('calendar.events');
        $response->assertRedirectContains('calendar.freebusy');
        // ⚠️ access_type=offline が無いとリフレッシュトークンが発行されず、1 時間で連携が切れる。
        $response->assertRedirectContains('access_type=offline');
        // ⚠️ prompt=consent が無いと「解除 → 再連携」でリフレッシュトークンが返らない（decisions #181）。
        $response->assertRedirectContains('prompt=consent');

        // Assert: state は「値 + 誰のものか + いつ発行したか」で預けられている
        $saved = session(self::SESSION_STATE);
        $this->assertIsArray($saved);
        $this->assertNotEmpty($saved['value']);
        $this->assertSame($coach->id, $saved['user_id']);
        $this->assertIsInt($saved['issued_at']);
    }

    /**
     * ⭐ 外部 URL を戻り先に指定しても、自サイト内に落とされる（オープンリダイレクト対策）。
     *
     * 支給 Blade が redirect_path をクエリで渡す作りなので、受け取る側で塞がないと
     * 「LMS を経由して外部サイトへ送られる」動線ができてしまう。
     */
    #[DataProvider('hostileRedirectPaths')]
    public function test_hostile_redirect_path_is_ignored(string $hostile): void
    {
        // Arrange: .env 未設定にしておくと redirect() で戻り先がそのまま観測できる
        config(['services.google.client_id' => null]);
        $coach = User::factory()->coach()->create();

        // Act
        $response = $this->actingAs($coach)
            ->get(route('settings.google-calendar.redirect', ['redirect_path' => $hostile]));

        // Assert: 面談設定画面へ戻る（外部へは行かない）
        $response->assertRedirect(route('settings.availability.index'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function hostileRedirectPaths(): array
    {
        return [
            '絶対 URL' => ['https://evil.example/'],
            'プロトコル相対 URL' => ['//evil.example'],
            'バックスラッシュ' => ['/\\evil.example'],
            '相対パス（/ 始まりでない）' => ['evil.example'],
            // ⚠️ 連携動線そのものを戻り先にするとリダイレクトループになる。
            //    .env 未設定なら「設定されていません」で同じ URL へ戻り続け、
            //    callback を指定した場合も検証失敗 → 同じ URL → 検証失敗… となる。
            '連携開始 URL 自身' => ['/settings/google-calendar/connect'],
            'callback URL 自身' => ['/settings/google-calendar/callback'],
            // ⚠️ Laravel のルート照合は rawurldecode したパスで行われるので、
            //    生の値だけで前方一致を見るとこれがすり抜ける。
            'エンコードで隠した連携開始 URL' => ['/%73ettings/google-calendar/connect'],
        ];
    }

    /**
     * ⭐ 自サイト内の正当なパスは、そのまま戻り先として採用される。
     *
     * ⚠️ 敵対パスを弾くテストだけでは `safeRedirectPath()` を `return $fallback;` の 1 行に
     *    潰しても全テストが緑になる（支給 Blade が渡す `/settings/availability` は
     *    fallback と同じ URI なので、手で触っても差が出ない）。肯定側の 1 本で固定する。
     */
    public function test_a_safe_redirect_path_is_honoured(): void
    {
        // Arrange: .env 未設定にしておくと、戻り先がそのまま観測できる
        config(['services.google.client_id' => null]);
        $coach = User::factory()->coach()->create();

        // Act
        $response = $this->actingAs($coach)
            ->get(route('settings.google-calendar.redirect', ['redirect_path' => '/meetings']));

        // Assert: fallback（面談設定）ではなく、指定したパスへ戻る
        $response->assertRedirect('/meetings');
    }

    /** .env 未設定の環境では 500 にせず案内して戻す。 */
    public function test_redirect_explains_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => null]);
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->get(route('settings.google-calendar.redirect'))
            ->assertRedirect(route('settings.availability.index'))
            ->assertSessionHas('error');
    }

    // ================================================================
    // コールバック（なりすまし拒否）
    // ================================================================

    /**
     * ⭐ state が一致しなければ連携させない。
     *
     * ⚠️ hash_equals() を `== true` に書き換えても、この 1 本が無ければ全テストが緑のまま。
     */
    public function test_callback_rejects_a_mismatched_state(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('exchangeCode');
        });

        // Act: セッションには別の値が入っている
        $response = $this->actingAs($coach)
            ->withSession([
                self::SESSION_STATE => [
                    'value' => 'THE-REAL-STATE',
                    'user_id' => $coach->id,
                    'issued_at' => Carbon::now()->timestamp,
                ],
                self::SESSION_REDIRECT_PATH => '/meetings',
            ])
            ->get(route('settings.google-calendar.callback', ['state' => 'FORGED', 'code' => 'CODE']));

        // Assert
        // ⚠️ 期待値は fallback（/settings/availability）と **別の URI**。同じにすると
        //    redirectPath() が常に fallback を返す実装に変えても緑のままになる。
        $response->assertRedirect('/meetings')->assertSessionHas('error');
        $this->assertDatabaseCount('google_credentials', 0);
    }

    /**
     * ⭐ 他人が発行した state では連携させない（セッション固定と組み合わせた不正連携）。
     *
     * Laravel はログイン時にセッション ID を再生成するがデータは引き継ぐため、
     * ログイン前に仕込まれた state はログイン後も生き残る。値の一致だけを見ていると、
     * 攻撃者の Google アカウントが被害コーチの LMS アカウントに紐付く。
     */
    public function test_callback_rejects_a_state_issued_for_another_user(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $attacker = User::factory()->coach()->create();
        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('exchangeCode');
        });

        // Act: 値は一致するが、発行したのは別ユーザー
        $response = $this->actingAs($coach)
            ->withSession([
                self::SESSION_STATE => [
                    'value' => 'SHARED-STATE',
                    'user_id' => $attacker->id,
                    'issued_at' => Carbon::now()->timestamp,
                ],
            ])
            ->get(route('settings.google-calendar.callback', ['state' => 'SHARED-STATE', 'code' => 'CODE']));

        // Assert
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('google_credentials', 0);
    }

    /** 古くなった state は無効。 */
    public function test_callback_rejects_an_expired_state(): void
    {
        $coach = User::factory()->coach()->create();
        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('exchangeCode');
        });

        $response = $this->actingAs($coach)
            ->withSession([
                self::SESSION_STATE => [
                    'value' => 'OLD-STATE',
                    'user_id' => $coach->id,
                    // 有効期限は 600 秒
                    'issued_at' => Carbon::now()->subMinutes(20)->timestamp,
                ],
            ])
            ->get(route('settings.google-calendar.callback', ['state' => 'OLD-STATE', 'code' => 'CODE']));

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('google_credentials', 0);
    }

    /**
     * ⚠️ state を持たない callback（＝第三者が踏ませた URL）でも 500 にせず拒否する。
     *
     * この 1 本は「error 分岐を state 検証より前に置く」書き方への歯止めでもある。
     * 前に置くと `?error=x` を踏ませるだけで state を捨てさせられ、正規の戻りが必ず失敗する。
     */
    public function test_callback_rejects_when_no_state_was_issued(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->get(route('settings.google-calendar.callback', ['error' => 'access_denied']))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('google_credentials', 0);
    }

    /** state が正しければ、トークンを保存して連携が成立する。 */
    public function test_callback_stores_the_credential_when_the_state_matches(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('exchangeCode')->once()->andReturn([
                'access_token' => 'AT-1',
                'refresh_token' => 'RT-1',
                'expires_at' => Carbon::now()->addHour(),
            ]);
        });

        // Act
        $response = $this->actingAs($coach)
            ->withSession([
                self::SESSION_STATE => [
                    'value' => 'GOOD-STATE',
                    'user_id' => $coach->id,
                    'issued_at' => Carbon::now()->timestamp,
                ],
                self::SESSION_REDIRECT_PATH => '/meetings',
            ])
            ->get(route('settings.google-calendar.callback', ['state' => 'GOOD-STATE', 'code' => 'CODE']));

        // Assert
        $response->assertRedirect('/meetings')->assertSessionHas('success');
        $this->assertDatabaseHas('google_credentials', [
            'user_id' => $coach->id,
            'calendar_id' => GoogleCalendarService::PRIMARY_CALENDAR_ID,
        ]);
    }

    /** トークン交換に失敗しても 500 にせず、案内して戻す。 */
    public function test_callback_survives_a_failed_token_exchange(): void
    {
        $coach = User::factory()->coach()->create();
        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('exchangeCode')->andThrow(new \RuntimeException('Google is down'));
        });

        $response = $this->actingAs($coach)
            ->withSession([
                self::SESSION_STATE => [
                    'value' => 'GOOD-STATE',
                    'user_id' => $coach->id,
                    'issued_at' => Carbon::now()->timestamp,
                ],
            ])
            ->get(route('settings.google-calendar.callback', ['state' => 'GOOD-STATE', 'code' => 'CODE']));

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('google_credentials', 0);
    }

    // ================================================================
    // 解除
    // ================================================================

    /** 解除すると行が消え、画面は「未連携」に戻る。 */
    public function test_destroy_removes_the_credential(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        GoogleCredential::factory()->forUser($coach)->create();

        // Act
        $response = $this->actingAs($coach)->delete(route('settings.google-calendar.destroy'));

        // Assert
        $response->assertRedirect(route('settings.availability.index'))->assertSessionHas('success');
        $this->assertDatabaseCount('google_credentials', 0);
        $this->assertNull($coach->fresh()->googleCredential);
    }

    /** 未連携のまま解除しても失敗しない（2 度押し / URL 直叩き）。 */
    public function test_destroy_is_idempotent(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)->delete(route('settings.google-calendar.destroy'))->assertRedirect();
        $this->actingAs($coach)->delete(route('settings.google-calendar.destroy'))->assertRedirect();

        $this->assertDatabaseCount('google_credentials', 0);
    }

    /** 他人の連携は消せない（自分の行だけが消える）。 */
    public function test_destroy_only_removes_the_actors_own_credential(): void
    {
        // Arrange: 2 人のコーチがそれぞれ連携している
        $coach = User::factory()->coach()->create();
        $other = User::factory()->coach()->create();
        GoogleCredential::factory()->forUser($coach)->create();
        $otherCredential = GoogleCredential::factory()->forUser($other)->create();

        // Act
        $this->actingAs($coach)->delete(route('settings.google-calendar.destroy'));

        // Assert: 相手の行は残っている
        $this->assertDatabaseCount('google_credentials', 1);
        $this->assertDatabaseHas('google_credentials', ['id' => $otherCredential->id]);
    }

    /**
     * ⭐ 不正な callback を踏まされても、保留中の正規フローは壊れない。
     *
     * ⚠️ これが無いと「検証より前に state を捨てる」実装に戻しても全テストが緑のまま。
     *    同意画面を開いている最中に第三者のリンクを踏まされると、正規の戻りが必ず失敗する
     *    ——連携を永久に妨害できる穴になる（2 巡目レビューで実際に見つかった）。
     */
    public function test_a_rejected_callback_does_not_break_the_pending_flow(): void
    {
        // Arrange: 正規の state を預けた状態（= 同意画面を開いている最中）
        $coach = User::factory()->coach()->create();
        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('exchangeCode')->once()->andReturn([
                'access_token' => 'AT-1',
                'refresh_token' => 'RT-1',
                'expires_at' => Carbon::now()->addHour(),
            ]);
        });

        $session = [
            self::SESSION_STATE => [
                'value' => 'PENDING-STATE',
                'user_id' => $coach->id,
                'issued_at' => Carbon::now()->timestamp,
            ],
            self::SESSION_REDIRECT_PATH => '/meetings',
        ];

        // Act ①: 第三者に踏まされた不正な callback
        $this->actingAs($coach)->withSession($session)
            ->get(route('settings.google-calendar.callback', ['error' => 'x']))
            ->assertSessionHas('error');
        $this->assertDatabaseCount('google_credentials', 0);

        // Act ②: そのあとに戻ってきた正規の callback
        // ⚠️ ここで withSession() を **書かない** ことが検証の肝。セッションは Act ① から
        //    引き継がれるので、Act ① が state を捨てていればここで失敗する。
        //    入れ直すと state が復活し、壊れた実装でも緑になってしまう（実測で確認）。
        $response = $this->actingAs($coach)
            ->get(route('settings.google-calendar.callback', ['state' => 'PENDING-STATE', 'code' => 'CODE']));

        // Assert: 妨害されずに連携できる
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('google_credentials', ['user_id' => $coach->id]);
    }

    /**
     * 同意画面で「キャンセル」を押した戻り（有効な state + error）は、中止として扱う。
     *
     * ⚠️ error 分岐と state 検証の順序を固定する 1 本。state が無効な場合の分岐とは
     *    メッセージが変わるので、入れ替えるとここが落ちる。
     */
    public function test_callback_treats_a_valid_error_response_as_cancellation(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('exchangeCode');
        });

        // Act
        $response = $this->actingAs($coach)
            ->withSession([
                self::SESSION_STATE => [
                    'value' => 'GOOD-STATE',
                    'user_id' => $coach->id,
                    'issued_at' => Carbon::now()->timestamp,
                ],
                self::SESSION_REDIRECT_PATH => '/meetings',
            ])
            ->get(route('settings.google-calendar.callback', [
                'state' => 'GOOD-STATE',
                'error' => 'access_denied',
            ]));

        // Assert: 「中止しました」であって「検証できませんでした」ではない
        $response->assertRedirect('/meetings');
        $this->assertSame('Google カレンダーとの連携を中止しました。', session('error'));
        $this->assertDatabaseCount('google_credentials', 0);
    }

    /**
     * ⭐ 連携中のコーチが連携し直せる（原典「連携は任意でいつでも切り替えられる」の後半）。
     *
     * ⚠️ 行を作り直すのではなく上書きする。user_id には unique があるので、
     *    create() で書くとここが SQL エラーになる。
     */
    public function test_reconnecting_updates_the_existing_credential(): void
    {
        // Arrange: 既に連携済み
        $coach = User::factory()->coach()->create();
        $existing = GoogleCredential::factory()->forUser($coach)->create([
            'access_token' => 'OLD-TOKEN',
            'connected_at' => Carbon::now()->subDays(7),
        ]);

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            // ⚠️ 2 回目の認可では Google が refresh_token を返さない場合がある
            $mock->shouldReceive('exchangeCode')->once()->andReturn([
                'access_token' => 'NEW-TOKEN',
                'refresh_token' => null,
                'expires_at' => Carbon::now()->addHour(),
            ]);
        });

        // Act
        $this->actingAs($coach)
            ->withSession([
                self::SESSION_STATE => [
                    'value' => 'GOOD-STATE',
                    'user_id' => $coach->id,
                    'issued_at' => Carbon::now()->timestamp,
                ],
            ])
            ->get(route('settings.google-calendar.callback', ['state' => 'GOOD-STATE', 'code' => 'CODE']))
            ->assertSessionHas('success');

        // Assert: 行は 1 つのまま、トークンは更新、refresh_token は消えない、連携日時は今に更新
        $this->assertDatabaseCount('google_credentials', 1);
        $fresh = $existing->fresh();
        $this->assertSame($existing->id, $fresh->id);
        $this->assertSame('NEW-TOKEN', $fresh->access_token);
        $this->assertNotNull($fresh->refresh_token);
        $this->assertTrue($fresh->connected_at->isToday());
    }

    // ================================================================
    // 連携状態の表示（原典: 状態に応じて連携・解除の操作を提示する）
    // ================================================================

    /**
     * ⭐ 連携中のコーチには「連携中」バッジ・カレンダー ID・連携日時・解除ボタンが出る。
     *
     * ⚠️ 支給 Blade は `connected_at?->format('Y-m-d H:i')` を呼ぶので、
     *    `GoogleCredential::$casts` から `connected_at` を外すと **画面が 500 になる**。
     *    この 1 本が無いと、cast を消しても全テストが緑のまま通る。
     *    トークンが画面に出ていないことも同時に見る。
     */
    public function test_meeting_settings_page_shows_the_linked_state(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        // ⚠️ calendar_id は本番では 'primary' 固定だが、テストでは **一意な値** を入れる。
        //    'primary' は同じ画面の `variant="primary"` や `bg-primary-500` にも一致するため、
        //    Blade から {{ $credential->calendar_id }} を消しても assertSee が緑のままになる（実測で確認）。
        GoogleCredential::factory()->forUser($coach)->create([
            'calendar_id' => 'coach-calendar@example.test',
            'access_token' => 'SECRET-ACCESS-TOKEN',
            'refresh_token' => 'SECRET-REFRESH-TOKEN',
        ]);

        // Act
        $response = $this->actingAs($coach)->get(route('settings.availability.index'));

        // Assert
        $response->assertOk();
        $response->assertSee('連携中');
        $response->assertSee('coach-calendar@example.test');
        $response->assertSee('連携を解除する');
        // ⚠️ トークンが画面に出ていないこと。
        //    支給 Blade はもともと calendar_id と connected_at しか出さないので、
        //    これは「誰かが画面にトークンを足した」ときに落ちる見張り。
        //    Model の $hidden（配列 / JSON 化からの除外）を守るものではない点に注意
        //    —— $hidden を外してもこの 2 行は緑のまま（変異テストで実測）。
        $response->assertDontSee('SECRET-ACCESS-TOKEN');
        $response->assertDontSee('SECRET-REFRESH-TOKEN');
    }

    /** 未連携のコーチには「未連携」バッジと連携ボタンが出る。 */
    public function test_meeting_settings_page_shows_the_unlinked_state(): void
    {
        // Arrange: GoogleCredential を作らない
        $coach = User::factory()->coach()->create();

        // Act
        $response = $this->actingAs($coach)->get(route('settings.availability.index'));

        // Assert
        $response->assertOk();
        $response->assertSee('未連携');
        $response->assertSee('Googleカレンダーと連携する');
    }

    /**
     * ⭐ 照合用トークンは 1 回きり。成功した直後に同じ値でもう一度は通らない。
     *
     * ⚠️ これが無いと、検証通過後の `forget()` を消しても全テストが緑のまま。
     *    同じ認可コードと state を再送されて連携が二重に成立する余地を残さない。
     */
    public function test_a_state_cannot_be_used_twice(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            // ⚠️ 2 回目は交換まで到達してはいけない
            $mock->shouldReceive('exchangeCode')->once()->andReturn([
                'access_token' => 'AT-1',
                'refresh_token' => 'RT-1',
                'expires_at' => Carbon::now()->addHour(),
            ]);
        });

        $session = [
            self::SESSION_STATE => [
                'value' => 'ONE-SHOT-STATE',
                'user_id' => $coach->id,
                'issued_at' => Carbon::now()->timestamp,
            ],
            self::SESSION_REDIRECT_PATH => '/meetings',
        ];
        $url = route('settings.google-calendar.callback', ['state' => 'ONE-SHOT-STATE', 'code' => 'CODE']);

        // Act ①: 1 回目は成功
        $this->actingAs($coach)->withSession($session)->get($url)->assertSessionHas('success');

        // Act ②: 同じ state でもう一度（セッションは ① から引き継がれる）
        $response = $this->actingAs($coach)->get($url);

        // Assert
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('google_credentials', 1);
    }

    /**
     * 形式が外れた入力でも、連携開始 URL へ戻してループさせない。
     *
     * ⚠️ FormRequest の既定は「直前のページへ戻す」だが、OAuth の callback における
     *    直前のページは連携開始 URL そのもの。`failedValidation()` の override を
     *    消すとここが落ちる。
     */
    public function test_callback_with_an_oversized_code_does_not_loop(): void
    {
        // Arrange: code の上限は 2048 文字
        $coach = User::factory()->coach()->create();
        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('exchangeCode');
        });

        // Act: 保留中の state を持った状態で踏まされる
        $response = $this->actingAs($coach)
            ->withSession([
                self::SESSION_STATE => [
                    'value' => 'PENDING-STATE',
                    'user_id' => $coach->id,
                    'issued_at' => Carbon::now()->timestamp,
                ],
                self::SESSION_REDIRECT_PATH => '/meetings',
            ])
            ->get(route('settings.google-calendar.callback', [
                'state' => 'X',
                'code' => str_repeat('a', 2049),
            ]));

        // Assert: 連携開始 URL ではなく、預けた戻り先へ返す
        $response->assertRedirect('/meetings')->assertSessionHas('error');
        $this->assertDatabaseCount('google_credentials', 0);

        // ⭐ Assert: 保留中の state を **消していない**。
        //    ここで消すと、第三者に長い code を踏ませるだけで正規の連携を妨害できる
        //    （2 巡目に塞いだ穴の再発）。
        $this->assertNotNull(session(self::SESSION_STATE));
    }
}
