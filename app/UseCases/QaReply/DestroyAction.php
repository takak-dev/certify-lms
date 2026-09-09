<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use Illuminate\Support\Facades\DB;

/**
 * 回答を削除するユースケース。
 *
 * 回答側には削除条件を設けない(decisions #39「投稿者本人のみ・条件なし」)。
 * 管理者のモデレーション削除も同じ Action を通る(実行者による分岐が無いため、
 * スレッド側の `QaThread\DestroyAction` と違って実行者を受け取らない)。
 * 物理削除(原典「物理削除のみ」)。
 */
final class DestroyAction
{
    public function __invoke(QaReply $reply): void
    {
        DB::transaction(fn () => $reply->delete());
    }
}
