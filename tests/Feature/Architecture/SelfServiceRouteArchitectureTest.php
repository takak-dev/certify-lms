<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 「本人だけが自分の情報を操作できる」を、ルートの形で保証していることを強制する Architecture テスト。
 *
 * S-B-06 の設定画面は Policy も FormRequest の認可判定も持たない(decisions #112)。
 * 成立している理由はただ一つで、対象のルートが**パラメータを持たない**ことにある。
 * `/settings/profile` には他人を指す場所が無く、Controller は `$request->user()` しか見ない。
 *
 * 裏を返すと、誰かが `/settings/profile/{user}` のようにパラメータを足した瞬間に前提が崩れ、
 * 認可判定が無いまま他人を更新できるようになる。既存の Feature テストは自分のデータしか使わないため
 * その変更を検知できないので、ルートの形そのものをここで固定する。
 *
 * 対照: `settings.default-enrollment.update` は `{enrollment}` を持つため、
 * `UpdateDefaultEnrollmentRequest::authorize()` で本人検証している。
 * 「ルート定義に `{}` があるか」が、認可判定を書くかどうかの判断基準になる。
 */
class SelfServiceRouteArchitectureTest extends TestCase
{
    public function test_self_service_settings_routes_take_no_parameters(): void
    {
        // Arrange: 認可判定を持たない「本人専用」ルート(S-B-06 で 5 本 + S-A-01 で 3 本 + S-A-03 で 3 本)
        $selfServiceRoutes = [
            'settings.profile.edit',
            'settings.profile.update',
            'settings.password.update',
            'settings.avatar.store',
            'settings.avatar.destroy',
            // Google カレンダー連携(S-A-01)。Policy も FormRequest の authorize() も持たず、
            // Controller は $request->user() しか見ない = まさにこのテストが守る前提そのもの。
            'settings.google-calendar.redirect',
            'settings.google-calendar.callback',
            'settings.google-calendar.destroy',
            // 追加面談パックの購入(S-A-03)。CheckoutCreateRequest / CheckoutSuccessRequest の
            // authorize() は true で、守っているのは ['auth', 'role:student', 'active-learning'] と
            // 「ルートが他人を指せない」という形だけ。購入対象はリクエストボディの meeting_pack_id で
            // 受け、作られる payments の持ち主は常に $request->user() 本人になる。
            // ⚠️ /meeting-quota/checkout/{pack} のようにパラメータを足すと、その前提が崩れる。
            'meeting-quota.checkout.select',
            'meeting-quota.checkout.create',
            'meeting-quota.checkout.success',
        ];
        $violations = [];

        // Act
        foreach ($selfServiceRoutes as $name) {
            $route = Route::getRoutes()->getByName($name);

            if ($route === null) {
                $violations[] = "{$name} が登録されていません";

                continue;
            }

            $parameters = $route->parameterNames();

            if ($parameters !== []) {
                $violations[] = "{$name} が引数を持っています: ".implode(', ', $parameters);
            }
        }

        // Assert
        $this->assertEmpty(
            $violations,
            "本人専用の設定ルートにパラメータを足すと、認可判定が無いまま他人を操作できるようになります。\n"
            ."パラメータが必要な操作にするなら、FormRequest の authorize() か Policy で本人検証を実装してください。\n"
            .implode("\n", $violations),
        );
    }
}
