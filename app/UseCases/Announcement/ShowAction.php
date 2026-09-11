<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Models\Announcement;

/**
 * 配信履歴の詳細を組み立てるユースケース。手本: `QaThread\ShowAction`。
 *
 * 詳細画面が参照する 3 つの関連を先読みする(announcement/management/show.blade.php:29,31,45)。
 * 1 件しか表示しないので N+1 は起きないが、参照する関連を Action 側で宣言しておくと
 * 「画面が何を必要としているか」がコードに残る。
 */
final class ShowAction
{
    public function __invoke(Announcement $announcement): Announcement
    {
        return $announcement->load(['targetCertification', 'targetUser', 'createdBy']);
    }
}
