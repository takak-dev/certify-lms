<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 受講登録(Enrollment)配下に受講生が自分で立てる個人目標。1 受講登録 : 多目標。
 *
 * 達成状態は Enum や boolean ではなく achieved_at(達成日時)1 本で表す。
 * NULL = 未達成 / 日時あり = 達成済。達成 → 解除 → 達成の履歴は残さない(チケットのスコープ外)。
 *
 * 削除は物理削除(履歴を残さない)。論理削除 + 復元 UI もスコープ外。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_goals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // 親の受講登録。参照がある間は受講登録の物理削除を止める(decisions #131)。
            // ⚠️ 受講解除では配下の目標を物理削除するが(decisions #135 / 面談2 Q44)、それを行うのは
            // アプリ側(Enrollment/DestroyAction.php)。受講登録は論理削除なので外部キーは発火しない。
            $table->foreignUlid('enrollment_id')
                ->constrained('enrollments')
                ->restrictOnDelete();
            // 画面の maxlength="100" に合わせる(enrollment-goal/_form.blade.php:22)
            $table->string('title', 100);
            // 任意入力。画面は maxlength="1000" だが、長さの検査は FormRequest の責務
            $table->text('description')->nullable();
            // 「いつまでに」だけを持つので date 型(時刻は扱わない)
            $table->date('target_date')->nullable();
            // 達成した瞬間の日時。未達成は NULL
            $table->timestamp('achieved_at')->nullable();
            $table->timestamps();

            // 索引は ->constrained() が自動で作る enrollment_id の 1 本だけにする。
            // 一覧の並び順(decisions #43)は「未達成を先に / 期日なしを末尾へ」を CASE 式で表すため、
            // 列の値ではなく計算結果で並ぶ。索引は列の値でしか並んでいないので、
            // ['enrollment_id', 'target_date'] のような索引を足しても並べ替えには使われない
            // (EXPLAIN の Extra が Using filesort のまま。実測で確認済み)。
            // 1 受講登録あたりの目標は数件〜数十件、画面も全件表示(LIMIT なし)なので実害もない。
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_goals');
    }
};
