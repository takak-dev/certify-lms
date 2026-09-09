<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;

/**
 * 質問スレッド詳細の表示データを組み立てるユースケース。
 *
 * 回答数は `show.blade.php:36` が `$thread->replies_count` を参照するため loadCount で先に数える。
 *
 * 詳細画面は投稿者・資格・回答一覧とその投稿者を参照するため(`show.blade.php`、`_reply.blade.php:18,21`)、
 * まとめて先読みする。`replies.user` の 2 段指定で、回答が増えてもクエリ本数が変わらないようにする
 * (要件「件数が増えても取得時間が線形に増えないように関連データを効率的に取得する」)。
 *
 * 回答は投稿順(古い順)に並べる。会話として読めるようにするため。
 */
final class ShowAction
{
    public function __invoke(QaThread $thread): QaThread
    {
        $thread->loadCount('replies')->load([
            'user',
            'certification',
            'replies' => fn ($query) => $query->with('user')->orderBy('created_at'),
        ]);

        // 回答カードは can('update', $reply) を回答ごとに呼び、Policy が $reply->qaThread を辿る。
        // 取得済みの親を差し込んでおくと、自分の回答が並んでも追加のクエリが発生しない
        $thread->replies->each->setRelation('qaThread', $thread);

        return $thread;
    }
}
