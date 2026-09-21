<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * meetings に google_event_id を追加する(S-A-01)。
 *
 * 連携済コーチの面談を Google カレンダーへ登録したとき、Google が採番したイベント ID を控える列。
 * キャンセル時に「どの予定を消すか」を指すために要る
 * (原典「その面談がキャンセルされると、登録済の予定も連動して削除される」)。
 *
 * null = Google に登録していない面談。次の 3 通りがある。
 *  1. コーチが未連携(原典「連携していないコーチには登録しない」)
 *  2. 連携前に成立していた面談(原典 スコープ外「連携前に成立した既存予約を遡って同期する機能」)
 *  3. 予約は成立したが Google への登録に失敗した(原典「通信に失敗しても面談の予約は止まらない」)
 *
 * ⚠️ この列があるおかげで、未登録の面談をキャンセルしたときに Google へ無駄な削除要求を投げずに済む。
 *    列を持たず「面談 ID から毎回同じイベント ID を計算する」設計も取れるが、
 *    その場合「登録したかどうか」がどこにも残らず、上記 1〜3 を区別できない。
 *
 * 長さ 1024: Google の仕様上イベント ID は最大 1024 文字
 * (events.insert「the length of the ID must be between 5 and 1024 characters」)。
 * 実際に採番される値はもっと短いが、仕様の上限に合わせて切り捨てが起きないようにしておく。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 面談予約テーブルが存在しない環境ではスキップする(既存の add_ 系 migration に倣う)。
        if (! Schema::hasTable('meetings')) {
            return;
        }

        Schema::table('meetings', function (Blueprint $table) {
            $table->string('google_event_id', 1024)->nullable()->after('meeting_url_snapshot');
        });

        // ⚠️ 索引は張らない。この列で検索する動線が無いため
        //    (常に「面談 1 件を引いてから、その行の値を見る」使い方で、
        //     google_event_id から面談を逆引きすることはない)。
        //    1024 文字の文字列に索引を張ると MySQL の索引長上限(InnoDB / utf8mb4 で 3072 バイト)に
        //    も近づくので、必要になってから足す。
    }

    public function down(): void
    {
        if (! Schema::hasTable('meetings')) {
            return;
        }

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('google_event_id');
        });
    }
};
