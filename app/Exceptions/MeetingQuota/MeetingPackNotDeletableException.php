<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingQuota;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 削除条件を満たさない面談パックを削除しようとした際の例外(HTTP 409)。
 * `MeetingPack\DestroyAction` が「公開中は削除できない」というドメインルールから throw する。
 *
 * 409 は app/Exceptions/Handler.php が拾い、直前の画面へ戻して error フラッシュを出す。
 * Controller 側で try-catch を書く必要はない。
 *
 * 手本: app/Exceptions/Certification/CertificationNotDeletableException.php
 */
final class MeetingPackNotDeletableException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('公開中の面談パックは削除できません。', $previous);
    }
}
