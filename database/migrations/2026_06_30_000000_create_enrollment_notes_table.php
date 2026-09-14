<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 受講登録(Enrollment)配下にコーチ / 管理者が残す業務メモ。1 受講登録 : 多メモ。
 *
 * 受講生本人には一切見せない(原典「メモは業務記録であり、受講生に見えると素直な観察ができなくなる」)。
 * 見せない仕組みは EnrollmentNotePolicy::viewAny が担当し、テーブル側には持たせない。
 *
 * 入力項目は本文 1 つだけ。タイトルも種別も期日も持たない(原典スコープ外「メモはフラットな本文のみ」)。
 * 削除は物理削除(原典「削除すると履歴は残らない」)。よって SoftDeletes も状態ログも設けない。
 *
 * 構造がいちばん近い既存テーブルは chat_messages(親 + 投稿者 + 本文 + 時刻)。索引の付け方はそちらに揃えた。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // 親の受講登録。参照がある間は受講登録の物理削除を止める(decisions #131)。
            // ⚠️ 姉妹機能の個人学習目標(S-B-05)は受講解除で配下を物理削除するが(decisions #135)、
            //    メモは逆で「消さず残す」(decisions #47 / #137。面談1・面談2 で確定)。
            //    Enrollment/DestroyAction.php にメモの削除を足さないこと。
            //    解除後に読ませない仕組みは Policy::viewAny が担当する。
            $table->foreignUlid('enrollment_id')
                ->constrained('enrollments')
                ->restrictOnDelete();

            // メモを書いた人(coach または admin)。
            // ⚠️ リレーション名は author()。列名もそれに合わせて author_id にする
            //    (enrollment-note/_list.blade.php:9,41 の ->with('author') / $note->author が根拠。decisions #33 ②)。
            // ⚠️ users は退会で論理削除されるため、この外部キーは退会では発火しない。
            //    退会したコーチの氏名は表示し続ける(decisions #46)ので、Model 側の author() に withTrashed を付ける。
            $table->foreignUlid('author_id')
                ->constrained('users')
                ->restrictOnDelete();

            // 画面の maxlength="2000" に対応する本文。長さの検査は FormRequest の責務なので
            // 列は text のままにする(enrollment_goals.description と同じ考え方)。
            $table->text('body');

            $table->timestamps();

            // 一覧は「ある受講登録のメモを新しい順に全件」で読む
            // (enrollment-note/_list.blade.php:9 の where enrollment_id + orderByDesc('created_at'))。
            // 絞り込みと並び替えが両方この索引で済むので複合で張る。手本: chat_messages の
            // index(['chat_room_id', 'created_at'])。
            //
            // ⚠️ enrollment_id / author_id の単独索引はここに書かない。外部キーを作ると
            //    InnoDB が索引を用意するため(author_id は enrollment_notes_author_id_foreign が
            //    自動で作られる)。enrollment_id はこの複合索引が先頭列を兼ねるので流用され、
            //    単独の索引は作られない(SHOW INDEX で実測)。
            $table->index(['enrollment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_notes');
    }
};
