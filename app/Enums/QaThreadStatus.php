<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 質問掲示板スレッド(QaThread)の解決状態を表す Enum。2 値モデル。
 *
 * ケース名が `Open` なのは、支給コード `Dashboard\FetchCoachDashboardAction.php:116,139` が
 * `QaThreadStatus::Open` を参照しているため(decisions #70)。値 'unresolved' は支給 Blade の
 * フィルタ値(`_filter.blade.php:5-7`)に合わせている。
 *
 * - Open: 未解決(投稿直後の初期値)
 * - Resolved: 解決済(投稿者本人が解決マークを付けた状態。resolved_at に時刻を持つ)
 *
 * 一覧の状態バッジは「解決済 / 未回答 / 対応中」の3種類だが、
 * 「未回答」と「対応中」は回答件数(replies_count)による Blade 側の出し分けであり、
 * 本 Enum の値ではない(`_thread-card.blade.php:8-12`)。
 */
enum QaThreadStatus: string
{
    case Open = 'unresolved';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => '未解決',
            self::Resolved => '解決済',
        };
    }
}
