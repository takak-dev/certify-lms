<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 面談リマインダーの配信タイミング (S-B-09)。
 *
 * 2 つのタイミングは 1 本のコマンドを `--window` で切り替えて動かす。
 * `notifications:send-meeting-reminders --window=eve` / `--window=one_hour_before`
 * (チケット原典が定めた署名。値はここから取っており、こちらで言い換えない)
 *
 * - Eve           … 前日 18:00 に、翌日 1 日分の予約を対象に送る (decisions #50)
 * - OneHourBefore … 5 分間隔で走り、開始 55〜65 分前に入った予約を送る (decisions #51)
 *
 * ⭐ この値が Enum になっている理由。
 * 同じ文字列がコマンドの引数・通知データの `reminder_window`・重複検査のクエリの
 * 3 箇所に現れる。素の文字列で散らすと 1 箇所打ち間違えても例外が出ず、
 * 「送信済みが見つからない」→ 毎回送り直す という形で静かに二重配信になる
 * (decisions #104)。値の定義を 1 箇所に寄せて、打ち間違いを起こせなくする。
 */
enum MeetingReminderWindow: string
{
    case Eve = 'eve';
    case OneHourBefore = 'one_hour_before';

    /**
     * コマンドの実行結果に出す日本語名。画面には出ない(この機能は画面を持たない)。
     *
     * メール本文と件名の文言はここではなく MeetingReminderNotification が持つ。
     * あちらは受信者に読ませる文章、こちらは実行した人がログで見る短い名前で、用途が違う。
     */
    public function label(): string
    {
        return match ($this) {
            self::Eve => '前日',
            self::OneHourBefore => '開始 1 時間前',
        };
    }
}
