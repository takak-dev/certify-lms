<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Models\Plan;

/**
 * admin 用の受講プラン詳細を取得するユースケース。
 * 受講者一覧とメタ情報カード(作成者 / 最終更新者)が使うリレーションを Eager Loading で揃える。
 *
 * 受講者は契約中(受講中 + 招待中)だけを載せる。定義は User::scopeContracted()(decisions #126)。
 * 支給 Blade は数値カード(show.blade.php:115)と受講者一覧(:135)で同じ $plan->users を読むので、
 * ここで絞れば 2 箇所が自動的に同じ数になる。
 *
 * 手本: app/UseCases/MeetingPack/ShowAction.php
 */
final class ShowAction
{
    public function __invoke(Plan $plan): Plan
    {
        // load() は「すでに取得済みのモデル」に後からリレーションを読み込む。
        // ルートモデルバインディングで $plan は取得済みなので with() ではなく load() を使う
        return $plan->load([
            'users' => fn ($q) => $q->contracted(),
            'createdBy',
            'updatedBy',
        ]);
    }
}
