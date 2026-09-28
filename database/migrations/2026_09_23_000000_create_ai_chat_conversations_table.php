<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 受講生と AI(Gemini)の相談 1 件分(S-A-02)。1 行 = 1 つの会話スレッド。
 *
 * 会話の「文脈」はこのテーブルの 2 本の外部キーで表す(decisions #199)。
 *   - section_id あり … 教材から始めた相談。Section のタイトルと本文の先頭を Gemini に添える
 *   - section_id なし / enrollment_id あり … 受講中の資格名だけを添える
 *   - どちらも null … 全般相談。資格名も添えない
 * 支給 Blade resources/views/ai-chat/_partials/context-badges.blade.php:8,11 が
 * この 2 本の有無からバッジ(📚 / 🎓 / 全般相談)を毎回計算しているため、
 * context_type のような列は持たない(持つと二重管理になる)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // 会話のオーナー。本人しか読み書きできない(原典 アクセス制御)。
            // restrictOnDelete は既存の「user に紐づく記録」に揃えた(payments:32 / chat_messages:23)。
            // users は SoftDeletes なので通常は発火しない。
            $table->foreignUlid('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // 相談の対象になっている受講登録(資格名の供給元)。
            // 既存の enrollment_id 外部キー(enrollment_notes:34 / enrollment_goals:28)と同じ restrict。
            $table->foreignUlid('enrollment_id')
                ->nullable()
                ->constrained('enrollments')
                ->restrictOnDelete();

            // 教材から始めた相談の Section。#201 により、同じ Section の会話は作り直さず再開する。
            //
            // ⚠️ nullOnDelete ではなく restrictOnDelete。既存の section_id 外部キーは
            //    learning_sessions:33 / section_progresses:24 / section_images:17 の 3 本とも restrict で、
            //    「教材が消せなくなる」のは既にこの DB の仕様。加えて Section を削除できるのは
            //    Draft のときだけ(app/UseCases/Section/DestroyAction.php:22)で、Draft の教材に
            //    受講生の会話がぶら下がることは実質起きない。
            $table->foreignUlid('section_id')
                ->nullable()
                ->constrained('sections')
                ->restrictOnDelete();

            // 会話の見出し。初回のやり取りの直後に AI が自動生成し、受講生が手で直せる。
            // 長さは支給 Blade の入力欄に合わせる(ai-chat/show.blade.php:152 の maxlength="100")。
            $table->string('title', 100);

            // 履歴サイドバーの並び順と「今日 / 過去 7 日 / 過去 30 日」の振り分けに使う
            // (ai-chat/show.blade.php:12,16)。まだ 1 通も無い会話があり得るため nullable
            // ——支給 Blade も :16 で `?->isToday()` と null 前提で書いている。
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();

            // 履歴サイドバー「自分の会話を last_message_at 降順で 30 件」(show.blade.php:10-14)。
            $table->index(['user_id', 'last_message_at']);

            // #201「同じ教材の会話が乱立しないように既存を探す」ための検索。
            $table->index(['user_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_conversations');
    }
};
