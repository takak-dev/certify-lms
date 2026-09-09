<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 回答の投稿ユースケース。手本: `QaThread\StoreAction`。
 *
 * 投稿者と親スレッドはサーバ側で決める(フォームの値は本文だけを使う)。
 * スレッドの状態(解決済 / 未解決)は変えない。解決マークは投稿者本人の操作でのみ動く。
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

            return $reply;
        });
    }
}
