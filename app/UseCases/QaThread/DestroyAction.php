<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\UserRole;
use App\Exceptions\QaBoard\QaThreadHasRepliesException;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 質問スレッドを削除するユースケース。手本: `CertificationCategory\DestroyAction`。
 *
 * 削除条件は実行者で変わる（decisions #37）:
 *
 * - 投稿者本人: 回答が 1 件でもあれば 409。他の受講生が書いた回答まで消えて集合知が失われるため
 * - 管理者: モデレーションのため無条件で削除できる。配下の回答も連動削除される
 *
 * 連動削除は DB の外部キー（`qa_replies.qa_thread_id` の cascade）が担うため、
 * ここで回答を個別に削除しない。物理削除（論理削除は使わない。原典「物理削除のみ」）。
 */
final class DestroyAction
{
    /**
     * @throws QaThreadHasRepliesException 投稿者本人の削除で、回答が 1 件以上ある
     */
    public function __invoke(QaThread $thread, User $actor): void
    {
        if ($actor->role !== UserRole::Admin && $thread->replies()->exists()) {
            throw new QaThreadHasRepliesException;
        }

        DB::transaction(fn () => $thread->delete());
    }
}
