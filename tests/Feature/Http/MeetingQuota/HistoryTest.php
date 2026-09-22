<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 面談回数履歴(GET /meeting-quota/history)が、返金の取り消し行を含んでも描けることを固定する(S-A-03)。
 *
 * ⚠️ **なぜこのテストが要るか。** 支給 Blade resources/views/meeting-quota/history.blade.php:14-25 の
 *    $typeBadge は `default` を持たない `match` で、MeetingQuotaTransactionType のケースを
 *    網羅している前提で書かれている。S-A-03 で `payment_refunded` を足したので、
 *    **ここに腕を足し忘れると、返金のあった受講生だけが履歴画面で 500 になる**
 *    (UnhandledMatchError)。開発中は返金データが無いと気付けない。
 *
 *    decisions #219 が「テストで機械的に止めている」としていたのは残数集計(remaining)の 1 点だけで、
 *    Blade の match と絞り込みは無防備だった(3 巡目のレビューで判明)。
 */
class HistoryTest extends TestCase
{
    use RefreshDatabase;

    /** 返金の取り消し行があっても履歴画面が描ける */
    public function test_history_renders_with_payment_refunded_row(): void
    {
        // Arrange: 購入(+3)と返金による取り消し(-3)が並ぶ受講生
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 0]);

        MeetingQuotaTransaction::create([
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
            'amount' => 3,
            'occurred_at' => now()->subHour(),
        ]);
        MeetingQuotaTransaction::create([
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::PaymentRefunded->value,
            'amount' => -3,
            'occurred_at' => now(),
        ]);

        // Act
        $response = $this->actingAs($student)->get(route('meeting-quota.history'));

        // Assert: 500 にならず、両方の種別のラベルが出る
        $response->assertOk();
        $response->assertSee('購入');
        $response->assertSee('返金');
    }

    /** 「返金」で絞り込める(絞り込みの選択肢は Enum の cases() から自動生成される) */
    public function test_history_can_be_filtered_by_payment_refunded(): void
    {
        // Arrange: 種別の違う 2 行
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 0]);

        MeetingQuotaTransaction::create([
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
            'amount' => 3,
            'note' => '購入の行',
            'occurred_at' => now()->subHour(),
        ]);
        MeetingQuotaTransaction::create([
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::PaymentRefunded->value,
            'amount' => -3,
            'note' => '返金の行',
            'occurred_at' => now(),
        ]);

        // Act: 種別 = 返金 で絞る
        $response = $this->actingAs($student)->get(route('meeting-quota.history', [
            'type' => MeetingQuotaTransactionType::PaymentRefunded->value,
        ]));

        // Assert: 返金の行だけが残る(備考は relatedPayment が無いとき note を出す)
        $response->assertOk();
        $response->assertSee('返金の行');
        $response->assertDontSee('購入の行');
    }
}
