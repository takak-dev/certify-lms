<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * キューに積まれたジョブの置き場(T-A-05。QUEUE_CONNECTION=database が読み書きする)。
 *
 * `sail artisan queue:table` が生成した Laravel 標準の形そのまま。独自に列を足さない。
 * 1 行 = 1 つの「まだ処理されていない仕事」。worker(`queue:work`)が取り出して実行し、成功したら行を消す。
 *
 * ⚠️ 主キーが ULID ではなく連番(bigIncrements)なのは意図的(decisions #269)。
 * Laravel の DatabaseQueue が「id の小さい順に取り出す」前提で書かれているため、形を変えない。
 * 同じ Laravel 標準の failed_jobs(2019_08_19_000000_create_failed_jobs_table.php)も連番。
 *
 * ⚠️ 時刻の列(reserved_at / available_at / created_at)は timestamp 型ではなく UNIX 秒の整数。
 * phpMyAdmin で見ると数字の羅列になるが、壊れているわけではない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            // キューの名前(既定は default)。worker は指定した名前の行だけを取り出す
            $table->string('queue')->index();
            // 何を実行するか(クラス名と引数)を JSON にしたもの
            $table->longText('payload');
            // 何回目の挑戦か。$tries を超えると failed_jobs へ移される
            $table->unsignedTinyInteger('attempts');
            // worker が取り出して処理中になった時刻。NULL = 誰も手を付けていない
            $table->unsignedInteger('reserved_at')->nullable();
            // この時刻を過ぎたら取り出してよい。リトライの待機($backoff)はここを未来にずらして実現する
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
