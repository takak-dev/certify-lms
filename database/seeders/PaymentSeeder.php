<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 開発用 追加面談パック購入記録シーダー(S-A-03)。
 *
 * **設計思想(Seeder 業界標準: 状態網羅 + 固定アカウント)**:
 *
 * 1. **固定の受講生**(student@certify-lms.test)に 完了 / 保留 / 失敗 を 1 件ずつ。
 *    動作確認・スクショ撮影で安定して参照できる状態を作る(UserSeeder:17 と同じ考え方)。
 *
 * 2. **デモ受講生**(Factory 生成の受講生。メール順で固定)にも完了 / 保留を投入し、
 *    管理者の面談パック詳細画面に購入履歴が複数人分並ぶ状態にする。
 *
 * ⚠️ **完了(succeeded)の購入だけ、面談回数の台帳に purchased の行を積む。**
 *    残数は meeting_quota_transactions の合計で決まり payments は見ていないため
 *    (MeetingQuotaService::remaining)、台帳を作らなければ残数に反映されない。
 *    原典 初期データ「状態ごとの履歴表示・**完了分のみ残数に反映されることを確認**」を、
 *    データとして示せる状態にするのが目的。
 *
 * 依存: UserSeeder(受講生) / MeetingPackSeeder(購入対象の SKU)。
 */
class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $packs = MeetingPack::query()->published()->ordered()->get();

        if ($packs->isEmpty()) {
            $this->command?->warn('PaymentSeeder: 公開中の面談パックがありません。先に MeetingPackSeeder を実行してください。');

            return;
        }

        $fixedStudent = User::query()->where('email', 'student@certify-lms.test')->first();

        if ($fixedStudent === null) {
            $this->command?->warn('PaymentSeeder: 固定の受講生が存在しません。先に UserSeeder を実行してください。');

            return;
        }

        // 固定の受講生: 完了 / 保留 / 失敗 を 1 件ずつ。完了だけが残数に効く
        $this->createSucceeded($fixedStudent, $packs->first());
        Payment::factory()->pending()->forUser($fixedStudent)->forPack($packs->get(1) ?? $packs->first())->create();
        Payment::factory()->failed()->forUser($fixedStudent)->forPack($packs->last())->create();

        // ⚠️ 返金済みも 1 件作る。原典の初期データ指定は「完了 / 保留 / 失敗 **など**」で必須ではないが、
        //    これが無いと PaymentStatus::Refunded のバッジと、面談回数履歴の「返金」絞り込みが
        //    初期データでは一度も現れない。どちらも面談で決まった仕様(decisions #210 / #219)なので、
        //    動作確認・証跡で見せられる状態にしておく。
        //    購入(+N)と取り消し(-N)の 2 行が対になるのは本番と同じ(HandleStripeWebhookAction)。
        $this->createRefunded($fixedStudent, $packs->first());

        // デモ受講生: 受講中の受講生 2 名(メール順)に完了 / 保留を 1 件ずつ。
        // 固定アカウントを除いて選ぶ(上で作った記録と重複させないため)。
        $demoStudents = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->whereNotIn('email', ['student@certify-lms.test', 'student-noquota@certify-lms.test'])
            // ⚠️ inRandomOrder() にしない。スクリーンショットや動画の証跡を撮り直したときに
            //    対象の受講生が変わると、同じ手順で同じ画面を再現できなくなる
            ->orderBy('email')
            ->limit(2)
            ->get();

        foreach ($demoStudents as $index => $student) {
            if ($index === 0) {
                $this->createSucceeded($student, $packs->last());

                continue;
            }

            Payment::factory()->pending()->forUser($student)->forPack($packs->first())->create();
        }
    }

    /**
     * 返金済みの購入を 1 件作る。**payments と台帳の 3 行で 1 組**
     * (購入 +N / 取り消し -N / 購入記録は refunded)。
     */
    private function createRefunded(User $user, MeetingPack $pack): void
    {
        $payment = Payment::factory()->refunded()->forUser($user)->forPack($pack)->create();

        MeetingQuotaTransaction::create([
            'user_id' => $user->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
            'amount' => $payment->quantity,
            'related_payment_id' => $payment->id,
            'occurred_at' => $payment->paid_at,
        ]);

        MeetingQuotaTransaction::create([
            'user_id' => $user->id,
            'type' => MeetingQuotaTransactionType::PaymentRefunded->value,
            'amount' => -$payment->quantity,
            'related_payment_id' => $payment->id,
            'note' => '返金による取り消し',
            'occurred_at' => $payment->paid_at?->copy()->addHours(2) ?? now(),
        ]);
    }

    /**
     * 決済完了の購入を 1 件作る。**payments と台帳の 2 行で 1 組**。
     *
     * Webhook(HandleStripeWebhookAction::handleCheckoutCompleted)が本番で行うのと同じ 2 つの書き込みを、
     * Seeder でも同じ組み合わせで再現する。片方だけ作ると
     * 「購入履歴には出るのに残数が増えていない」という実際には起きない状態になり、
     * 動作確認の土台として使えなくなる。
     */
    private function createSucceeded(User $user, MeetingPack $pack): void
    {
        $payment = Payment::factory()->succeeded()->forUser($user)->forPack($pack)->create();

        MeetingQuotaTransaction::create([
            'user_id' => $user->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
            'amount' => $payment->quantity,
            'related_payment_id' => $payment->id,
            'occurred_at' => $payment->paid_at,
        ]);
    }
}
