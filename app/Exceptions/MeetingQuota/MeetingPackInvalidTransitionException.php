<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingQuota;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 面談パックの状態遷移(publish / archive / unarchive)が不正な開始状態から呼ばれた際の例外(HTTP 409)。
 *
 * 遷移は3本だけ。 下書き --publish--> 公開中 --archive--> アーカイブ --unarchive--> 下書き
 * 原典「不正な順序での状態変更はできない」に対応する。
 *
 * コンストラクタを private にして、遷移ごとの static メソッドからしか作れないようにしてある。
 * こうすると文言が1箇所に集まり、呼び出し側でメッセージを書き間違えられない。
 *
 * 手本: app/Exceptions/Certification/CertificationInvalidTransitionException.php
 */
final class MeetingPackInvalidTransitionException extends ConflictHttpException
{
    public static function forPublish(): self
    {
        return new self('下書きの面談パックのみ公開できます。');
    }

    public static function forArchive(): self
    {
        return new self('公開中の面談パックのみアーカイブできます。');
    }

    public static function forUnarchive(): self
    {
        return new self('アーカイブ済みの面談パックのみ下書きに戻せます。');
    }

    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
