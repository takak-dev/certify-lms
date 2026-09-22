<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuota;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 追加面談パックの購入導線(GET/POST /meeting-quota/checkout・GET /meeting-quota/success)を検証する(S-A-03)。
 *
 * 原典 要件のうち、ここで固定するのは 3 つ ——
 *   ①公開中の面談パックだけが購入画面に並ぶ
 *   ②公開中でないパックは **URL を直接指定しても購入できない**
 *   ③学習中でない受講生・コーチ・管理者は購入できない
 *
 * ⚠️ Stripe への通信はモックで差し替える。phpunit.xml で STRIPE_SECRET を空にしてあるので
 *    差し替え忘れても外部へは出ないが、その場合は 409 になるため気付ける。
 */
class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Stripe の決済画面作成を差し替える。
     *
     * @param ?string $expectedPackId 呼ばれるはずのパック。null なら「一度も呼ばれない」ことを検証する
     */
    private function mockStripe(?string $expectedPackId = null): void
    {
        $this->mock(StripeService::class, function (MockInterface $mock) use ($expectedPackId): void {
            if ($expectedPackId === null) {
                // 購入が成立しないケース: Stripe を呼んではいけない
                $mock->shouldReceive('createCheckoutSession')->never();

                return;
            }

            $mock->shouldReceive('createCheckoutSession')
                ->once()
                // 購入対象のパックがそのまま渡ること(別のパックの値段で決済させない)
                ->withArgs(fn (MeetingPack $pack, string $clientReferenceId): bool => $pack->id === $expectedPackId && $clientReferenceId !== '')
                ->andReturn([
                    'id' => 'cs_test_mocked_session',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_mocked_session',
                ]);
        });
    }

    // ------------------------------------------------------------------
    // 購入画面(GET)
    // ------------------------------------------------------------------

    /** 公開中のパックだけが並ぶ(原典 要件「公開中の面談パック一覧を閲覧できる」) */
    public function test_select_lists_only_published_packs(): void
    {
        // Arrange: 3 つの状態をすべて用意する
        $student = User::factory()->student()->inProgress()->create();
        $published = MeetingPack::factory()->published()->create(['name' => '公開中パック']);
        MeetingPack::factory()->draft()->create(['name' => '下書きパック']);
        MeetingPack::factory()->archived()->create(['name' => 'アーカイブ済パック']);

        // Act
        $response = $this->actingAs($student)->get(route('meeting-quota.checkout.select'));

        // Assert: 支給 Blade が要求する変数名は $plans
        $response->assertOk();
        $response->assertViewIs('meeting-quota.checkout-select');
        $response->assertViewHas('plans', fn ($plans) => $plans->pluck('id')->all() === [$published->id]);
        $response->assertSee('公開中パック');
        $response->assertDontSee('下書きパック');
        $response->assertDontSee('アーカイブ済パック');
    }

    /** コーチは購入画面に入れない(原典 ユーザーストーリー「購入動線は受講生専用機能」) */
    public function test_coach_cannot_open_select(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();

        // Act
        $response = $this->actingAs($coach)->get(route('meeting-quota.checkout.select'));

        // Assert: role:student middleware が弾く
        $response->assertForbidden();
    }

    /** 管理者も購入画面に入れない */
    public function test_admin_cannot_open_select(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->get(route('meeting-quota.checkout.select'));

        // Assert
        $response->assertForbidden();
    }

    /** 修了した受講生は購入できない(原典 要件「学習中でない受講生は購入できない」) */
    public function test_graduated_student_cannot_open_select(): void
    {
        // Arrange: 卒業済み = プラン機能から外れている
        $graduated = User::factory()->student()->graduated()->create();

        // Act
        $response = $this->actingAs($graduated)->get(route('meeting-quota.checkout.select'));

        // Assert: active-learning middleware(EnsureActiveLearning)が 403 にする
        $response->assertForbidden();
    }

    /** 未ログインはログイン画面へ */
    public function test_guest_is_redirected_to_login(): void
    {
        // Act
        $response = $this->get(route('meeting-quota.checkout.select'));

        // Assert
        $response->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // 購入の開始(POST)
    // ------------------------------------------------------------------

    /** 購入すると pending の記録が作られ、Stripe の決済画面へ送り出される */
    public function test_create_makes_pending_payment_and_redirects_to_stripe(): void
    {
        // Arrange: 5 回 12,000 円のパックを 1 つ公開しておく
        $student = User::factory()->student()->inProgress()->create();
        $pack = MeetingPack::factory()->published()->withCount(5)->withPrice(12000)->create();
        $this->mockStripe(expectedPackId: $pack->id);

        // Act
        $response = $this->actingAs($student)
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id]);

        // Assert: 外部ドメインへの遷移(redirect()->away())
        $response->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_mocked_session');

        // Assert: 購入記録は pending。金額と回数は**購入時点のマスタの値を控える**
        //         (原典「決済額 / 購入回数は購入時点の値を控えとして保存」)
        $this->assertDatabaseHas('payments', [
            'user_id' => $student->id,
            'meeting_pack_id' => $pack->id,
            'amount' => 12000,
            'quantity' => 5,
            'status' => PaymentStatus::Pending->value,
            // Stripe が発行した Session ID が書き戻されている(冪等性の鍵)
            'stripe_checkout_session_id' => 'cs_test_mocked_session',
        ]);

        // Assert: この時点では残数は 1 回も増えていない(増えるのは Webhook 受信時だけ)
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    /**
     * ⭐ 原典 要件「公開中でない面談パックは、購入動線に並ばないだけでなく、
     * URL を直接指定しても購入できない」。画面にボタンが無くても POST は届くため、
     * 画面の出し分けだけでは要件を満たさない(CLAUDE.md §3-7)。
     */
    public function test_unpublished_pack_cannot_be_purchased_even_by_direct_post(): void
    {
        // Arrange: 下書きのパック(購入画面には並ばない)
        $student = User::factory()->student()->inProgress()->create();
        $draft = MeetingPack::factory()->draft()->create();
        $this->mockStripe(expectedPackId: null); // Stripe を呼んではいけない

        // Act: 購入画面を経由せず、直接 POST する
        $response = $this->actingAs($student)
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $draft->id]);

        // Assert: 409 は Handler が直前の画面へ戻して error フラッシュを出す(_共通ルール.md §2)
        $response->assertRedirect();
        $response->assertSessionHas('error', 'この面談パックは現在購入できません。');

        // Assert: 購入記録は 1 件も作られない
        $this->assertDatabaseCount('payments', 0);
    }

    /** アーカイブ済みのパックも同様に購入できない */
    public function test_archived_pack_cannot_be_purchased(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $archived = MeetingPack::factory()->archived()->create();
        $this->mockStripe(expectedPackId: null);

        // Act
        $response = $this->actingAs($student)
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $archived->id]);

        // Assert
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('payments', 0);
    }

    /** 存在しないパック ID は入力エラーとして戻す(FormRequest の exists:) */
    public function test_unknown_pack_id_is_rejected_as_validation_error(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $this->mockStripe(expectedPackId: null);

        // Act: 形式は ULID だが実在しない ID
        $response = $this->actingAs($student)->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => '01JQZZZZZZZZZZZZZZZZZZZZZZ',
        ]);

        // Assert
        $response->assertSessionHasErrors('meeting_pack_id');
        $this->assertDatabaseCount('payments', 0);
    }

    /** コーチは購入を開始できない */
    public function test_coach_cannot_start_checkout(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $pack = MeetingPack::factory()->published()->create();
        $this->mockStripe(expectedPackId: null);

        // Act
        $response = $this->actingAs($coach)
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }

    // ------------------------------------------------------------------
    // 完了画面(GET)
    // ------------------------------------------------------------------

    /** 自分の購入なら内容が表示される */
    public function test_success_shows_own_payment(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $payment = Payment::factory()->succeeded()->forUser($student)->create();

        // Act
        $response = $this->actingAs($student)->get(route('meeting-quota.checkout.success', [
            'session_id' => $payment->stripe_checkout_session_id,
        ]));

        // Assert
        $response->assertOk();
        $response->assertViewHas('payment', fn (?Payment $p) => $p?->id === $payment->id);
    }

    /**
     * ⚠️ session_id は URL に載る値なので、他人の ID を貼られる経路がある。
     * 推測困難さを認可の代わりにせず、持ち主で絞る。
     */
    public function test_success_does_not_show_other_students_payment(): void
    {
        // Arrange: 他人の購入記録
        $student = User::factory()->student()->inProgress()->create();
        $otherPayment = Payment::factory()->succeeded()->create();

        // Act: その session_id を自分のブラウザで開く
        $response = $this->actingAs($student)->get(route('meeting-quota.checkout.success', [
            'session_id' => $otherPayment->stripe_checkout_session_id,
        ]));

        // Assert: 画面は出るが中身は渡らない(支給 Blade は @if ($payment) で出し分ける)
        $response->assertOk();
        $response->assertViewHas('payment', null);
    }

    /** session_id が無くても完了画面は開ける(ブックマークからの直アクセス) */
    public function test_success_works_without_session_id(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($student)->get(route('meeting-quota.checkout.success'));

        // Assert: エラーにしない。「支払ったのにエラー画面」を避ける
        $response->assertOk();
        $response->assertViewHas('payment', null);
    }

    /**
     * Stripe が未設定の環境では、購入ボタンを押すと 409 で案内される。
     *
     * README が「未設定の場合、購入ボタンを押すと『決済サービスに接続できませんでした』と
     * 案内され、既存の機能は従来どおり動作します」と約束している経路。
     * ⚠️ phpunit.xml が STRIPE_SECRET を空に固定しているので、**モックを付けずに叩くだけ**で
     *    この経路を通れる(外部通信は起きない = T-A-04 を待つ必要がない)。
     */
    public function test_checkout_returns_conflict_when_stripe_is_not_configured(): void
    {
        // Arrange: StripeService をモックしない(= 本物。ただし設定が空)
        $student = User::factory()->student()->inProgress()->create();
        $pack = MeetingPack::factory()->published()->create();

        // Act
        $response = $this->actingAs($student)
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id]);

        // Assert: 500 ではなく 409 → 直前の画面へ戻り、理由が伝わる
        $response->assertRedirect();
        $response->assertSessionHas('error', '決済サービスに接続できませんでした。時間をおいてお試しください。');

        // Assert: 購入記録は残るが pending のまま(残数には影響しない)
        $this->assertDatabaseHas('payments', [
            'meeting_pack_id' => $pack->id,
            'status' => PaymentStatus::Pending->value,
            'stripe_checkout_session_id' => null,
        ]);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    /**
     * 学習中でない受講生は「購入の実行」もできない(原典 要件)。
     *
     * ⚠️ 画面(GET)だけでなく POST を直接叩く経路も塞がっていることを固定する。
     *    ルートを 1 本グループの外へ出す事故を機械的に止めるため。
     */
    public function test_graduated_student_cannot_start_checkout(): void
    {
        // Arrange
        $graduated = User::factory()->student()->graduated()->create();
        $pack = MeetingPack::factory()->published()->create();
        $this->mockStripe(expectedPackId: null);

        // Act
        $response = $this->actingAs($graduated)
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }

    /** 学習中でない受講生は完了画面も開けない */
    public function test_graduated_student_cannot_open_success(): void
    {
        // Arrange
        $graduated = User::factory()->student()->graduated()->create();

        // Act
        $response = $this->actingAs($graduated)->get(route('meeting-quota.checkout.success'));

        // Assert
        $response->assertForbidden();
    }
}
