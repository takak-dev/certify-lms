<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use Illuminate\Support\Facades\DB;

/**
 * 質問スレッドを解決済にするユースケース。手本: `Certification\PublishAction`。
 *
 * 解決時刻を `resolved_at` に残す。状態ログテーブルは設けない(decisions #69)。
 * 既に解決済の場合は何もしない(冪等)。二重送信や戻るボタンでの再送で
 * `resolved_at` が上書きされ、解決した時刻が後ろにずれるのを防ぐ。
 */
final class ResolveAction
{
    public function __invoke(QaThread $thread): QaThread
    {
        if ($thread->status === QaThreadStatus::Resolved) {
            return $thread;
        }

        return DB::transaction(function () use ($thread): QaThread {
            $thread->forceFill([
                'status' => QaThreadStatus::Resolved,
                'resolved_at' => now(),
            ])->save();

            return $thread->refresh();
        });
    }
}
