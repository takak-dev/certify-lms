<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 質問掲示板のスレッドに付く回答1件。投稿できるのは受講生とコーチ(管理者は投稿できない)。
 *
 * 編集 / 削除は投稿者本人のみ。管理者はモデレーションとして削除だけ行える。
 * ネスト回答・ベスト回答指定は持たない(チケットのスコープ外)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_replies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // スレッドが消えたら配下の回答も消える(decisions #37 の「連動削除」)
            $table->foreignUlid('qa_thread_id')
                ->constrained('qa_threads')
                ->cascadeOnDelete();
            // 投稿者。参照がある間はユーザーの物理削除を止める(qa_threads.user_id と同じ扱い)
            $table->foreignUlid('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->text('body');
            $table->timestamps();

            // 詳細画面は1スレッドの回答を投稿順に並べる
            $table->index(['qa_thread_id', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_replies');
    }
};
