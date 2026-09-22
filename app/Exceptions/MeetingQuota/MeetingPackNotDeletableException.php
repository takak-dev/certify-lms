<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingQuota;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 削除条件を満たさない面談パックを削除しようとした際の例外(HTTP 409)。
 * `MeetingPack\DestroyAction` がドメインルールから throw する。
 *
 * 拒否の理由は 2 つあり、利用者にはそれぞれ別の文言を見せる。
 *   - 公開中(原典 要件「公開中の面談パックは削除できない」。まず公開を停止してから消す)
 *   - 購入履歴がある(decisions #38 / #123。売った記録は残す)
 *
 * ⚠️ 判定の順番は decisions #127「取り消せない障害を先に」に従う(購入履歴 → 状態)。
 *
 * コンストラクタを private にして理由ごとの static メソッドからしか作れないようにしてある。
 * こうすると文言が 1 箇所に集まり、呼び出し側で書き間違えられない
 * (手本: app/Exceptions/MeetingQuota/MeetingPackInvalidTransitionException.php)。
 *
 * 409 は app/Exceptions/Handler.php が拾い、直前の画面へ戻して error フラッシュを出す。
 * Controller 側で try-catch を書く必要はない。
 */
final class MeetingPackNotDeletableException extends ConflictHttpException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forPublished(): self
    {
        return new self('公開中の面談パックは削除できません。');
    }

    /**
     * ⚠️ DB 側にも payments.meeting_pack_id の restrictOnDelete があり、
     *    ここを通さずに削除しようとすると外部キー違反(500)になる。
     *    利用者に理由が伝わる形で止めるのがこのメソッドの役割で、FK は最後の砦。
     */
    public static function forPurchased(): self
    {
        return new self('購入履歴のある面談パックは削除できません。');
    }
}
