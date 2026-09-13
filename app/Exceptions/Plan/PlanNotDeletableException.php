<?php

declare(strict_types=1);

namespace App\Exceptions\Plan;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 削除条件を満たさない受講プランを削除しようとした際の例外(HTTP 409)。
 * `Plan\DestroyAction` が「下書き かつ 受講者0名 かつ プラン履歴0件」のドメインルールから throw する。
 *
 * 条件の出どころは3つ。
 * - 状態: 支給 Blade の確認ダイアログ(plan/management/show.blade.php:88「下書きかつ受講者未紐づきの場合のみ削除可能」)
 * - 受講者: users.plan_id が restrictOnDelete()。消すと外部キー違反で 500 になる
 * - 履歴: user_plan_logs.plan_id も restrictOnDelete()。原典 背景「プラン履歴が孤立して監査ができなくなる」
 *
 * 理由ごとに文言を分けるため、コンストラクタを private にして static メソッドからのみ作る。
 *
 * 手本: app/Exceptions/MeetingQuota/MeetingPackNotDeletableException.php
 */
final class PlanNotDeletableException extends ConflictHttpException
{
    public static function forStatus(): self
    {
        return new self('下書きのプランのみ削除できます。');
    }

    /**
     * 判定は withTrashed() で全員を数えるので、卒業済み・退会済みも止める。
     * 画面の「受講者数」は契約中(受講中 + 招待中)しか数えないため 0 名に見えることがあり、
     * 文言で「誰が残っているのか」を補わないと管理者が原因を追えない。
     */
    public static function forUsers(): self
    {
        return new self('このプランに紐づく受講生が残っているため削除できません。卒業済み・退会済みの受講生も対象です。');
    }

    public static function forLogs(): self
    {
        return new self('このプランのプラン履歴が残っているため削除できません。');
    }

    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
