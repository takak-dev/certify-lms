<?php

declare(strict_types=1);

namespace App\Exceptions\QaBoard;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 回答が付いているスレッドを投稿者本人が削除しようとした際の例外（HTTP 409）。
 *
 * `QaThread\DestroyAction` から throw される。他の受講生が書いた回答まで消えて
 * 集合知が失われるため、投稿者本人による削除は回答が 0 件のときだけ許す（decisions #37）。
 * 管理者のモデレーション削除はこの制限を受けない。
 */
final class QaThreadHasRepliesException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('回答が付いている質問は削除できません。', $previous);
    }
}
