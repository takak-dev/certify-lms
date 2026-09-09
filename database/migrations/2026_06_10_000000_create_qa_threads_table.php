<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 質問掲示板のスレッド(質問1件)。投稿できるのは受講生のみ。
 *
 * 資格に紐づくが、受講登録は前提にしない(受講していない資格にも質問できる)。
 * 解決済 / 未解決は投稿者本人だけが切り替え、解決時刻を `resolved_at` に残す。
 * 削除は物理削除(論理削除は行わない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_threads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // 投稿者。ユーザーを消してもスレッドが宙に浮かないよう、参照がある間は削除を止める
            $table->foreignUlid('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            // 質問対象の資格。資格が消えれば掲示板の文脈も失われるため連動削除
            $table->foreignUlid('certification_id')
                ->constrained('certifications')
                ->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('body');
            // QaThreadStatus の ->value を格納する(unresolved / resolved)
            $table->string('status', 20)->default('unresolved');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // 一覧の既定は新着順。資格 / 状態での絞り込みと組み合わせて引く
            $table->index(['certification_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_threads');
    }
};
