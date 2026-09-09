<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;
use Illuminate\Support\Facades\DB;

/**
 * 質問スレッドの編集ユースケース。手本: `QuestionCategory\UpdateAction`。
 *
 * 更新できるのはタイトルと本文のみ。資格・状態・投稿者は変えない
 * (`certification_id` は UpdateRequest の rules() に無く、`status` / `resolved_at` / `user_id` は
 * `QaThread::$fillable` にも無いため、`update()` に混ざっても書き込まれない)。
 */
final class UpdateAction
{
    /**
     * @param array{title: string, body: string} $validated QaThread/UpdateRequest::rules() で検証済
     */
    public function __invoke(QaThread $thread, array $validated): QaThread
    {
        return DB::transaction(function () use ($thread, $validated): QaThread {
            $thread->update($validated);

            return $thread->refresh();
        });
    }
}
