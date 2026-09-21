<?php

declare(strict_types=1);

namespace App\UseCases\GoogleCalendar;

use App\Models\GoogleCredential;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google から受け取った認可コードをトークンに交換し、コーチの連携を成立させるユースケース(S-A-01)。
 *
 * コーチ本人であることの検証は Controller 側(`role:coach` middleware + 認証ユーザーから取得 +
 * state の本人一致)で完了済の前提。
 *
 * ⚠️ **失敗しても例外を投げず false を返す。** 外部通信の失敗を握るのは呼び出し側ではなく
 *    このクラス —— 同じ PR の SyncMeetingAction / RemoveMeetingEventAction と流儀を揃えている。
 *    Controller を「FormRequest 受取 → Action 呼び出し → レスポンス」の 3 手に保つため
 *    (CLAUDE.md §3-2)。
 */
final class ConnectAction
{
    public function __construct(
        private readonly GoogleCalendarService $google,
    ) {}

    /**
     * @return bool 連携できたか。false なら理由はログに残っている
     */
    public function __invoke(User $coach, string $code): bool
    {
        try {
            $token = $this->google->exchangeCode($code);
        } catch (Throwable $e) {
            // ⚠️ 例外オブジェクトをそのまま渡さない。Guzzle の例外はリクエスト本体を抱えており、
            //    トークン交換の POST ボディには client_secret と認可コードが載っている
            //    (vendor/guzzlehttp/guzzle/src/Exception/RequestException.php:19)。
            //    残すのは「誰が」「なぜ」だけにする。
            Log::warning('Google カレンダーの連携に失敗しました。', [
                'user_id' => $coach->id,
                'exception' => $e::class,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        DB::transaction(function () use ($coach, $token): void {
            // ⚠️ create() ではなく firstOrNew()。既に連携中のコーチがもう一度連携ボタンを押しても
            //    成立させるため(画面上は「連携中」だと解除ボタンしか出ないが、URL を直接叩けば到達できる)。
            //    user_id には unique 制約があるので、create() だと 2 回目が SQL エラーになる。
            $credential = GoogleCredential::firstOrNew(['user_id' => $coach->id]);

            $credential->calendar_id = GoogleCalendarService::PRIMARY_CALENDAR_ID;
            $credential->access_token = $token['access_token'];
            $credential->expires_at = $token['expires_at'];

            // 再連携したときは「いつから連携しているか」を今に更新する。
            // created_at は行を最初に作った時刻のまま動かないので、両者はここで意図的に食い違う。
            $credential->connected_at = Carbon::now();

            // ⚠️ refresh_token は「返ってきたときだけ」差し替える。null で上書きしない。
            //    Google は同意済みのアカウントに対して refresh_token を返さないことがあり
            //    (公式ドキュメント「the refresh token is only returned ... in the initial request」)、
            //    素直に代入すると手元の有効なトークンを消してしまう。消えると更新手段が無くなり、
            //    アクセストークンが切れた時点で連携が勝手に切れる。
            //    GoogleCalendarService は prompt=consent で毎回発行させているが、ここでも保険をかける。
            if ($token['refresh_token'] !== null) {
                $credential->refresh_token = $token['refresh_token'];
            }

            $credential->save();
        });

        return true;
    }
}
