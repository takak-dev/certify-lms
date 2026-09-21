<?php

declare(strict_types=1);

namespace App\UseCases\GoogleCalendar;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * コーチの Google カレンダー連携を解除するユースケース(S-A-01)。
 *
 * やることは google_credentials の行を消すだけ。**Google 側には一切触らない**。
 *
 * ⚠️ 意図的にやらないことが 2 つある。
 *  1. 登録済イベントの削除 — 支給 Blade が利用者にそう約束している
 *     (settings/_partials/tab-meeting.blade.php:113-114
 *      「既存の予約は LMS 内には残り、Google 側のイベントは削除されません」)。
 *  2. Google 側の同意(アクセス権)の取り消し — 原典のスコープに無い。
 *     ⚠️ このため再連携時に Google が「同意済み」と見なして refresh_token を返さない。
 *        その穴は GoogleCalendarService が prompt=consent を常に付けることで塞いでいる。
 *
 * 連携済の面談予約も LMS 内には残る(1 と同じ理由)。よって状態ログも設けない
 * (CLAUDE.md §3-4 の但し書き「可逆で実害の無い状態変更には設けない」。再連携すれば元に戻る)。
 */
final class DisconnectAction
{
    /**
     * 連携していないコーチに対して呼ばれても何も起きない(削除件数 0 で正常終了)。
     * 「解除ボタンを 2 回押す」「未連携で URL を直接叩く」を例外にしないため。
     */
    public function __invoke(User $coach): void
    {
        // googleCredential() はリレーションの問い合わせを返すので、そのまま delete() すると
        // 「この user_id の行」だけを消す DELETE になる。
        // 先に取得してから $credential->delete() とするより 1 クエリ少なく、行が無くても分岐が要らない。
        DB::transaction(fn () => $coach->googleCredential()->delete());
    }
}
