<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * meeting_quota_transactions.related_payment_id に外部キー制約を追加する(S-A-03)。
 *
 * 支給コードの指示どおりの追加。create_meeting_quota_transactions_table.php:20-21 に
 * 「payments テーブル(追加面談購入 Feature 所有)未作成のため FK 制約は付けない
 *   (payments 導入時に追加する)」と書かれており、payments を作る本チケットが引き取る。
 *
 * ⚠️ related_payment_id は nullable のまま。FK が要求するのは「null でない値は payments に
 *    実在すること」だけで、初期付与 / 消費 / 返却 / 管理者付与の行は null のまま通る。
 *
 * ⚠️ 先例の 2026_05_30_000000_add_related_meeting_fk_to_meeting_quota_transactions.php は冒頭に
 *    `if (! Schema::hasTable('meetings')) { return; }` という門番を持つが、ここでは付けない。
 *    あちらは相手テーブルが別 Feature の持ち物で「存在しないのが正常」な環境がありうるのに対し、
 *    payments は同じ PR の 2026_09_22_000000 が作る = ファイル名の順で必ず先に走るため、
 *    存在しない状況を作れない。無意味な門番を置くと、異常時に FK が付かないまま
 *    migration が成功として記録され、不正な related_payment_id が入るまで気付けなくなる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            // restrictOnDelete: 購入に紐づく台帳行が残っている payments を消せなくする。
            // 先例の related_meeting_id と同じ(2026_05_30_000000_add_related_meeting_fk_to_meeting_quota_transactions.php:21-24)。
            // 面談回数の増減履歴は監査対象なので、親を消して履歴を欠落させない。
            $table->foreign('related_payment_id')
                ->references('id')
                ->on('payments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->dropForeign(['related_payment_id']);
        });
    }
};
