<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;

/**
 * admin 用の面談パック詳細を取得するユースケース。
 * メタ情報カードが表示する作成者 / 最終更新者を Eager Loading で揃える。
 *
 * ⚠️ 購入履歴(payments)はここで読み込まない。App\Models\Payment は S-A-03(Stripe 連携)で作るため
 * 現時点では存在せず、支給 Blade も class_exists() でガードしている
 * (meeting-pack/management/show.blade.php:125,133)。S-A-03 でこの Action に追加する。
 *
 * 手本: app/UseCases/Certification/ShowAction.php
 */
final class ShowAction
{
    public function __invoke(MeetingPack $plan): MeetingPack
    {
        // load() は「すでに取得済みのモデル」に後からリレーションを読み込む。
        // ルートモデルバインディングで $plan は取得済みなので with() ではなく load() を使う
        return $plan->load(['createdBy', 'updatedBy']);
    }
}
