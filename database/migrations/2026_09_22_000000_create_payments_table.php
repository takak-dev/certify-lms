<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 追加面談パックの購入記録(S-A-03)。1 行 = 1 回の決済(Stripe Checkout Session 1 件)。
 *
 * 行は「受講生が購入ボタンを押した時点」で status = pending として作り、Stripe からの
 * Webhook を受けて succeeded へ更新する(failed にする経路はアプリに無い。decisions #228)。決済完了を受けてから作るのではない理由は 2 つ。
 *   ① 支給 Blade resources/views/meeting-quota/success.blade.php:22 が、Stripe からの
 *      リダイレクト直後に $payment を表示する。ブラウザのリダイレクトと Webhook は別経路で
 *      到着順が保証されないため、後から作る設計では完了画面が空になる。
 *   ② 原典 初期データが「決済状態の異なる購入記録(完了 / 保留 / 失敗)」を要求している。
 *      pending の行は「決済を始めたがまだ完了していない」状態でしか生まれない。
 *
 * ⚠️ 残面談回数を増やすのは Webhook で succeeded になった瞬間だけ(原典 要件)。この行が
 *    できただけでは 1 回も加算しない。加算の実体は meeting_quota_transactions 側にある。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // 購入者。restrictOnDelete は同じ「お金・回数の台帳」である
            // meeting_quota_transactions:15 に揃えた(退会は users の SoftDeletes なので通常発火しない)。
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();

            // 購入した面談パック。購入履歴の残るパックを消せなくする最後の砦
            // (アプリ側では MeetingPack/DestroyAction が先に分かりやすいエラーで止める。decisions #38 / #123)。
            $table->foreignUlid('meeting_pack_id')->constrained('meeting_packs')->restrictOnDelete();

            // ⚠️ amount / quantity は「購入時点の控え」。meeting_packs を参照するだけにしない。
            //    原典 共通の振る舞い「決済額 / 購入回数は購入時点の値を控えとして保存し、
            //    後からマスタを変更しても過去の購入を監査できる」。
            //    型は控え元の meeting_packs.price / meeting_count に合わせる。
            $table->unsignedInteger('amount');
            $table->unsignedSmallInteger('quantity');

            // PaymentStatus(pending / succeeded / failed / refunded)。
            // Enum を string 列で持つのは既存の流儀(meeting_packs.status / meetings.status と同じ)。
            $table->string('status', 20)->default('pending');

            // 決済が確定した日時。pending / failed では null(支給 Blade も
            // meeting-pack/management/show.blade.php:166 で `?->` を使い null を前提にしている)。
            $table->timestamp('paid_at')->nullable();

            // ⭐ 冪等性の鍵。同じ Webhook が重複して届いても、この 1 行を排他ロックして
            //    「もう succeeded なら何もしない」と判定する。unique はアプリのロジックが
            //    すり抜けた場合の最後の砦(Stripe 公式 docs.stripe.com/webhooks
            //    「Handle duplicate events」= data.object の ID で重複を見分ける)。
            //
            // ⚠️ nullable なのは、行を作る順序が「payments を作る → Stripe に Session を
            //    作らせる → 返ってきた cs_... を書き戻す」だから。MySQL の unique 索引は
            //    NULL を重複と見なさないため、未確定の行が複数あっても衝突しない。
            $table->string('stripe_checkout_session_id', 255)->nullable()->unique();

            // 返金通知(charge.refunded)から購入行を逆引きするために持つ。
            // ⚠️ Stripe の Refund オブジェクトが持つのは payment_intent / charge で、
            //    Checkout Session の ID は入っていない(docs.stripe.com/api/refunds)。
            //    これが無いと decisions #210「返金されたら残面談回数を減らす」を実装できない。
            $table->string('stripe_payment_intent_id', 255)->nullable();

            $table->timestamps();

            // 購入履歴を新しい順に引く用途(管理者の面談パック詳細 = 直近 20 件 / 受講生の履歴画面)。
            $table->index(['user_id', 'created_at']);
            $table->index('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
