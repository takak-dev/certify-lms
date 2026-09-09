<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 質問スレッドの新規投稿ユースケース。手本: `QuestionCategory\StoreAction`。
 *
 * 投稿者は引数で受け取った認証ユーザーで固定する(フォームの値は使わない)。
 * 状態は必ず未解決から始まるため、`$validated` ではなくここで明示的に設定する
 * (`status` / `resolved_at` は `QaThread::$fillable` に含まれない)。
 */
final class StoreAction
{
    /**
     * @param array{certification_id: string, title: string, body: string} $validated QaThread/StoreRequest::rules() で検証済
     */
    public function __invoke(User $author, array $validated): QaThread
    {
        return DB::transaction(function () use ($author, $validated): QaThread {
            $thread = new QaThread($validated);
            $thread->user_id = $author->id;
            $thread->status = QaThreadStatus::Open;
            $thread->save();

            return $thread;
        });
    }
}
