<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 管理者が受講生集合へ一斉配信したお知らせ。配信実績そのものを兼ねる。
 *
 * 配信は不可逆(再配信 / 編集 / 取消なし)のため、UPDATE も DELETE も想定しない。
 * SoftDelete は採用しない(enrollment_status_logs と同じ考え方。履歴は消さない)。
 *
 * ⭐ dispatched_count / dispatched_at は「配信した瞬間の事実」を固定して持つ。
 * 詳細画面を開くたびに対象人数を数え直す作りにすると、受講生の増減で過去の実績が
 * 変わってしまい、原典の「配信実績を後から監査できる状態にしたい」を満たせない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title', 200);
            $table->text('body');
            // AnnouncementTargetType の ->value を格納する(all_students / certification / user)
            $table->string('target_type', 20);

            // 配信対象の絞り込み先。target_type が対応するタイプのときだけ値が入る。
            // 3 本とも nullOnDelete にしているのは、参照先が消えても配信実績の行を残すため
            // (手本: enrollment_status_logs.changed_by_user_id:24-27)。
            // ⚠️ certifications は論理削除ではない(deleted_at 列が無く、Certification は SoftDeletes を
            // use していない)。下書き状態の資格は本当に消えるため、この FK は実際に発火する。
            // cascadeOnDelete にしていたら配信実績ごと消えていた。users は論理削除。
            $table->foreignUlid('target_certification_id')
                ->nullable()
                ->constrained('certifications')
                ->nullOnDelete();
            $table->foreignUlid('target_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // 実際に通知を送った人数。対象0件でも配信は成功扱いで 0 を記録する(decisions #49)
            $table->unsignedInteger('dispatched_count');
            // 配信時刻。配信は即時のみ(予約配信はスコープ外)なので、行の作成時に必ず埋まる
            $table->timestamp('dispatched_at');

            // 配信した管理者。退会しても配信実績は残す
            $table->foreignUlid('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            // 履歴一覧は配信時刻の降順で引く(新しい順)
            $table->index('dispatched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
