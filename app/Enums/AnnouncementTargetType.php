<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * お知らせの配信対象タイプ。`announcements.target_type` に入る。
 *
 * ⚠️ ケース名・並び順・label() の有無は画面が決めている
 * (resources/views/announcement/management/_partials/target-fields.blade.php:14,24,27,30,33)。
 * cases() は宣言順に返すため、ここに書いた順がそのままラジオボタンの並び順になる。
 * 既定で選択されるのは AllStudents(同 blade:9)。
 *
 * 📌 値(文字列)は画面が決めていない。blade は `$type->value` としか書いておらず、
 *    中身が何であっても動く。他の Enum に揃えて小文字のスネークケースにした。
 */
enum AnnouncementTargetType: string
{
    /** 受講中の全受講生へ配信する */
    case AllStudents = 'all_students';

    /** 指定した資格に受講登録している受講生へ配信する */
    case Certification = 'certification';

    /** 指定した受講生 1 名だけへ配信する */
    case User = 'user';

    /**
     * 画面に出す日本語名。
     *
     * 配信フォームのラジオの見出し(target-fields.blade.php:24)と、
     * 履歴一覧・詳細のバッジ(announcement/management/index.blade.php:54 / announcement/management/show.blade.php:27)で使う。
     * バッジに収まる長さにするため、説明文は blade 側に置いたまま短い語だけを返す。
     */
    public function label(): string
    {
        // match は switch と違い、値を「返す」式。$this(自分自身のケース)で分岐している
        return match ($this) {
            self::AllStudents => '全受講生',
            self::Certification => '資格指定',
            self::User => 'ユーザー指定',
        };
    }
}
