<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Support\Facades\DB;

/**
 * 回答の投稿ユースケース。手本: `QaThread\StoreAction`。
 *
 * 投稿者と親スレッドはサーバ側で決める(フォームの値は本文だけを使う)。
 * スレッドの状態(解決済 / 未解決)は変えない。解決マークは投稿者本人の操作でのみ動く。
 *
 * 投稿が確定したらスレッドの投稿者へ通知する(S-B-04)。
 */
final class StoreAction
{
    /**
     * @param array{body: string} $validated QaReply/StoreRequest::rules() で検証済
     */
    public function __invoke(User $author, QaThread $thread, array $validated): QaReply
    {
        return DB::transaction(function () use ($author, $thread, $validated): QaReply {
            $reply = new QaReply($validated);
            $reply->qa_thread_id = $thread->id;
            $reply->user_id = $author->id;
            $reply->save();

            // 質問した本人へ通知する。回答したのが本人自身なら送らない
            // (自分の操作を自分に知らせない。面談通知を「相手方のみ」とした decisions #77 と同じ考え方)。
            //
            // afterCommit を使うのは、この先で例外が出て INSERT が巻き戻ったときに
            // 通知だけが残るのを防ぐため。手本: Chat/StoreMessageAction.php の broadcast 発火。
            if ($thread->user_id !== $author->id) {
                DB::afterCommit(function () use ($thread, $reply): void {
                    // 受け取ってよい相手かどうかは通知クラス側で判定する
                    // (DeliversToActiveUsersOnly / decisions #76)
                    $thread->user?->notify(new QaReplyReceivedNotification($reply));
                });
            }

            return $reply;
        });
    }
}
