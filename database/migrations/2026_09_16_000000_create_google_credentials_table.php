<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * コーチが任意連携した Google アカウントの OAuth 認証情報。1 ユーザー : 1 連携(S-A-01)。
 *
 * この行が存在する = 連携中。連携解除は行の物理削除で表す(状態列は持たない)。
 * 支給 Blade が `$user->googleCredential` の有無だけで連携中 / 未連携を出し分けているため
 * (resources/views/settings/_partials/tab-meeting.blade.php:82,87)、
 * 「解除済みの行を残す」設計にすると画面が連携中のままになる。
 *
 * ⚠️ 解除しても Google 側のイベントは消さない(同 Blade:113-114 が受講生にそう説明している)。
 *    よって削除済み行を追跡する必要がなく、SoftDeletes も状態ログも設けない
 *    (CLAUDE.md §3-4「可逆で実害の無い状態変更にはログを設けない」。再連携すればいつでも戻る)。
 *
 * 構造がいちばん近い既存テーブルは meeting_memos(親 1 件に対して 1 行・unique 外部キー)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // 連携したユーザー。1 ユーザー 1 連携を DB レベルで固定する
            // (手本: meeting_memos の foreignUlid('meeting_id')->unique())。
            //
            // ⚠️ 列名を coach_id ではなく user_id にした理由:
            //    連携できるのはコーチだけ(原典 スコープ外「受講生 / 管理者の Google アカウント連携」)だが、
            //    それを担保するのは Policy / middleware の責務で、列名で表すものではない。
            //    既存で coach_id を使っているのは coach_availabilities と meetings で、どちらも
            //    「コーチという役割そのものが主題」か「同じ行から users を 2 回参照する」テーブル。
            //    ここは users への参照が 1 本だけの「そのユーザーの持ち物」なので、
            //    certificates / user_plan_logs / learning_sessions と同じ user_id に揃えた。
            //    これにより User::googleCredential() が Eloquent の既定どおり user_id を引ける。
            //
            // ⚠️ 退会は論理削除(users の SoftDeletes)なのでこの外部キーは通常発火しない。
            //    物理削除されたときは連携情報も一緒に消す。残しても参照先を失うだけで意味がなく、
            //    かつトークンという秘密情報を宙に浮かせないため(手本: coach_availabilities の cascadeOnDelete)。
            $table->foreignUlid('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            // 連携先カレンダーの ID。実値は 'primary' 固定
            // (原典 スコープ外「連携カレンダーの選択 UI — プライマリカレンダー固定」)だが、
            // 支給 Blade が画面に表示するため列として持つ(同 Blade:104)。
            $table->string('calendar_id', 255);

            // OAuth 2.0 のトークン。
            // ⚠️ 平文で保存する(原典 スコープ外「認証情報の暗号化保存 — 本チケットのスコープ外」)。
            //    本番運用での暗号化推奨は README に明記する(原典が明示的に要求している)。
            //
            // access_token は Google の仕様上 255 文字を超えうるので text にする。
            // refresh_token は初回の同意時にしか返らない場合があるため nullable
            // (再同意なしで連携し直したときに null で上書きしてトークンを失わないよう、
            //  更新側で「返ってきたときだけ差し替える」扱いにする)。
            $table->text('access_token');
            $table->text('refresh_token')->nullable();

            // access_token の失効時刻。これを過ぎていたら refresh_token で更新してから API を呼ぶ。
            // 原典 共通の振る舞い「連携は一度設定すれば継続して使える状態を保つ」がこの列の根拠。
            $table->dateTime('expires_at');

            // 連携した日時。支給 Blade が画面に表示する(同 Blade:107 の connected_at?->format())。
            // created_at と値は近いが別に持つ。再連携で行を作り直しても「いつから連携しているか」を
            // 意図して表す列にしておくため。
            $table->dateTime('connected_at');

            $table->timestamps();

            // ⚠️ user_id の索引はここに書かない。unique() が一意索引を作るので検索にも使われる
            //    (enrollment_notes の外部キーと同じ考え方)。
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_credentials');
    }
};
