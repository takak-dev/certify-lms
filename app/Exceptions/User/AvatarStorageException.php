<?php

declare(strict_types=1);

namespace App\Exceptions\User;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * アバター画像のストレージ操作(保存 / 削除)が失敗した場合に throw される。
 * トランザクション ROLLBACK と組み合わせて、DB と Storage の不整合(orphan ファイル / 参照切れの URL)を防ぐ。
 * public disk は 'throw' => false(config/filesystems.php:53)で保存失敗時も例外を投げないため、
 * StoreAvatarAction は putFileAs() の戻り値を見て本例外を throw する。
 *
 * 手本: App\Exceptions\Content\SectionImageStorageException
 */
final class AvatarStorageException extends HttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(500, 'アイコン画像の保存に失敗しました。時間をおいて再度お試しください。', $previous);
    }
}
