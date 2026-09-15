<?php

declare(strict_types=1);

namespace App\Exceptions\Certification;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 修了証 PDF の生成、または private disk への保存に失敗した場合に throw される(HTTP 500)。
 *
 * トランザクション ROLLBACK と組み合わせて「PDF の実体が無いのに certificates の行だけある」状態を防ぐ
 * (原典の要件「PDF 生成に失敗した場合、修了証は発行されていない状態に保つ」)。
 *
 * 業務ルール違反(409)ではなくサーバ側の失敗なので 500。
 * 手本: app/Exceptions/Content/SectionImageStorageException.php(同じくファイル操作の失敗)
 */
final class CertificatePdfGenerationException extends HttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(500, '修了証 PDF の生成または保存に失敗しました。時間をおいて再度お試しください。', $previous);
    }
}
