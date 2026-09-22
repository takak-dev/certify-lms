<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;

/**
 * admin 用の面談パック詳細を取得するユースケース。
 * メタ情報カードが表示する作成者 / 最終更新者を Eager Loading で揃える。
 *
 * 購入履歴(payments)も一緒に読み込む(S-A-03 / decisions #124)。支給 Blade は購入者の氏名と
 * メールアドレスまで表示するため(meeting-pack/management/show.blade.php:151,153)、載せないと購入件数ぶん N+1 になる。
 *
 * ⚠️ **直近 20 件に絞る。** 支給 Blade 自身が「件(直近 20 件のみ表示)」(show.blade.php:126)と
 * 「購入履歴(直近 20 件)」(:131)と書いており、全件を渡すと画面の説明と中身が食い違う。
 *
 * 手本: app/UseCases/Certification/ShowAction.php
 */
final class ShowAction
{
    public function __invoke(MeetingPack $plan): MeetingPack
    {
        // load() は「すでに取得済みのモデル」に後からリレーションを読み込む。
        // ルートモデルバインディングで $plan は取得済みなので with() ではなく load() を使う
        return $plan->load([
            'createdBy',
            'updatedBy',
            // 新しい購入から 20 件。クロージャでリレーションに条件を足せる
            'payments' => fn ($query) => $query->latest()->limit(20),
            // 購入者の氏名・メール(meeting-pack/management/show.blade.php:151,153)。payments の入れ子として一括取得する
            'payments.user',
        ]);
    }
}
