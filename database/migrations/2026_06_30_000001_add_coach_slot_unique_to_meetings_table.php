<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * meetings に (coach_id, scheduled_at) の UNIQUE を追加する。
 *
 * 同コーチ × 同時刻のダブルブッキングを DB レベルで禁止する最終防衛線(B-A-01)。
 * アプリ側は既にこの制約の存在を前提にしており、MeetingController の予約処理が
 * UniqueConstraintViolationException を捕捉して MeetingNoAvailableCoachException(409) へ変換する。
 *
 * status を問わない(canceled も重複扱いにする)。面談2 Q46 で確認済み(decisions #143)——
 * 「一度キャンセルした時間枠が二度と予約できなくなる」副作用を受け入れたうえで、
 * 状態に依存しない「同じコーチ・同じ時刻は物理的に 1 件まで」を保証する。
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
            $table->unique(['coach_id', 'scheduled_at']);
        });

        // down() が退避用に作る索引を残さない(up/down を対称にする)。
        // 一度も rollback していない環境には存在しないので、有無を見てから落とす。
        if (Schema::hasIndex('meetings', 'meetings_coach_id_index')) {
            Schema::table('meetings', function (Blueprint $table) {
                $table->dropIndex('meetings_coach_id_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('meetings')) {
            return;
        }

        // ⚠️ UNIQUE を先に落とせない。MySQL は coach_id の外部キー(create_meetings_table.php:27)を
        // 維持するための索引としてこの UNIQUE を流用しており、単独で落とすと
        // 「1553 Cannot drop index ...: needed in a foreign key constraint」になる(実測)。
        // 代わりの索引を先に作ってから落とす。
        if (! Schema::hasIndex('meetings', 'meetings_coach_id_index')) {
            Schema::table('meetings', function (Blueprint $table) {
                $table->index('coach_id');
            });
        }

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropUnique(['coach_id', 'scheduled_at']);
        });
    }
};
