<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * 受講生 1 人あたりの 1 日の送信回数の上限に達したときの例外(S-A-02・HTTP 429)。
 *
 * 原典の非機能要件「受講生 1 人あたりの 1 日の送信回数に上限を設ける」。
 * 上限の値は config('ai-chat.daily_message_limit')(既定 20。decisions #234)。
 *
 * ⚠️ 429 でなければならない。支給 JS resources/js/ai-chat/chat-client.js:48-51 が
 *    429 のときだけ `type: 'rate-limit'` とし、「本日の利用上限に達しました。明日 0:00 以降に
 *    再度ご利用ください。」を出す(resources/js/ai-chat/floating-widget.js:156 / full-screen.js:145)。
 *    ほかの番号にすると、この案内が出ず「送信に失敗しました」に落ちる。
 *
 * ⚠️ この例外が HTML リクエストで Handler まで届くと、429 の専用ページが無いため
 *    Laravel の既定ページになる(decisions #231 が指摘した「429 の受け皿が無い」問題)。
 *    ただし 429 を返す唯一の経路 AiChatController::storeMessage は支給 JS 専用で、
 *    支給 JS は必ず `Accept: application/json` を送る(chat-client.js:40)。
 *    会話作成経路(HTML)は StoreAction が捕まえてフラッシュに変える。
 */
final class DailyMessageLimitExceededException extends TooManyRequestsHttpException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct(null, "本日の利用上限({$limit} 通)に達しました。");
    }
}
