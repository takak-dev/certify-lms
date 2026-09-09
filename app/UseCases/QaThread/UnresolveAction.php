<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use Illuminate\Support\Facades\DB;

/**
 * 質問スレッドを未解決に戻すユースケース。`ResolveAction` の対。
 *
 * 解決時刻はクリアする(未解決なのに解決時刻が残っている状態を作らない)。
 * 既に未解決の場合は何もしない(冪等)。
 */
final class UnresolveAction
{
    public function __invoke(QaThread $thread): QaThread
    {
        if ($thread->status === QaThreadStatus::Open) {
            return $thread;
        }

        return DB::transaction(function () use ($thread): QaThread {
            $thread->forceFill([
                'status' => QaThreadStatus::Open,
                'resolved_at' => null,
            ])->save();

            return $thread->refresh();
        });
    }
}
