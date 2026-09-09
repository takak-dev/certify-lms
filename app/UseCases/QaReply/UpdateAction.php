<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use Illuminate\Support\Facades\DB;

/**
 * 回答の編集ユースケース。更新できるのは本文のみ。
 *
 * 親スレッド・投稿者は変えない(`qa_thread_id` / `user_id` は `QaReply::$fillable` に無いため、
 * 配列に混ざっても書き込まれない)。
 */
final class UpdateAction
{
    /**
     * @param array{body: string} $validated QaReply/UpdateRequest::rules() で検証済
     */
    public function __invoke(QaReply $reply, array $validated): QaReply
    {
        return DB::transaction(function () use ($reply, $validated): QaReply {
            $reply->update($validated);

            return $reply->refresh();
        });
    }
}
