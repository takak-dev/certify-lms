<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * アプリ内通知の格納先(Laravel 標準の database チャネルが読み書きする)。
 *
 * 独自テーブルは設計せず、Laravel の `Notifiable` トレイトが前提とするスキーマに合わせる。
 * `app/Models/User.php:29` が `Notifiable` を use しているため、
 * `$user->notifications()` / `$user->unreadNotifications()` がこのテーブルを引くようになる。
 *
 * 雛形(vendor/laravel/framework/src/Illuminate/Notifications/Console/stubs/notifications.stub)から
 * 3 箇所変えている——`morphs` → `ulidMorphs` / `text` → `json` / 未読件数用のインデックス追加。
 * 理由は各カラムのコメントに記す。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            // このテーブルの主キーだけは ULID ではなく UUID。
            // Laravel は送信時に Str::uuid() で採番する(NotificationSender.php:102,189)。
            //
            // ⚠️ 技術的には ULID にもできる。NotificationSender.php:140-141 が
            //    `if (! $notification->id)` で守っており、通知クラス側で $this->id を先に埋めれば
            //    そちらが使われる(Notification::$id は public)。
            //    それをしないのは、全通知クラスが採番の責務を負うことになるため。
            //    1 つでも書き忘れると主キーの型が混ざる。S-B-08 / S-B-09 で通知クラスが増えるので、
            //    Laravel が必ず採番する形に任せる方を選んだ
            // (CLAUDE.md §3-3「主キーは ULID」の唯一の例外。decisions #78)
            $table->uuid('id')->primary();

            // 通知クラスの完全修飾名(例: App\Notifications\QaReplyReceivedNotification)。
            // 画面は data['notification_type'] を見てアイコンを出し分けるが、
            // Laravel 自身は通知オブジェクトを復元するのにこの列を使う
            $table->string('type');

            // 宛先。`notifiable_type`(モデル名) + `notifiable_id`(主キー) の 2 列になる。
            // ⚠️ 雛形の morphs() を使うと notifiable_id が unsignedBigInteger になり、
            //    ULID の users.id(char 26) が入らない。
            //    morphs() は Builder::$defaultMorphKeyType を見て分岐するが、本プロジェクトは
            //    未設定で既定の 'int' のため numericMorphs() に落ちる(Blueprint.php:1530-1538)。
            //    同じ理由で personal_access_tokens.tokenable_id は bigint のままになっている
            //    (Sanctum を使う S-A-05 で顕在化する。pending-list に記録済み)
            $table->ulidMorphs('notifiable');

            // 表示内容。title / message / notification_type / url などをキーで持つ構造化データ。
            // 雛形は text だが、本プロジェクトは text を「人が書いた自由文」に使い、
            // 構造化データには json を使う(mock_exam_tables.php:83 の generated_question_ids)。
            // S-B-09 はこの列へ JSON path クエリを投げて面談リマインダーの重複配信を検査する(decisions #36)
            $table->json('data');

            // 未読 / 既読の判定。null なら未読(Laravel 標準の約束)
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // 未読件数は TopBar のバッジが全ページで数える(NotificationBadgeComposer.php:31)。
            // ulidMorphs() が張る (notifiable_type, notifiable_id) だけでは read_at を絞り込めないため追加する
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
