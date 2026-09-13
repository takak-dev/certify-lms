<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * 受講プラン管理の各エンドポイントが、実際に PlanPolicy を判定していることを検証する。
 *
 * なぜこのテストが要るのか。
 * 他のテストにある「受講生・コーチは 403」は、Policy が無くてもテストが緑のままになる。
 * 403 を返しているのは role:admin ミドルウェアであって、Policy ではないため
 * (ルートが routes/web.php:195 の admin グループの中にある)。
 * つまり Controller と FormRequest から authorize / can を全部消しても、それらのテストは落ちない。
 *
 * ここでは Gate::after を仕掛けて「どの権限名が判定されたか」を記録し、
 * エンドポイントごとに期待する権限が実際に問われたことを固定する。
 * authorize() の書き忘れや、リファクタで消えた場合にここが落ちる。
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * リクエストを1回投げて、その間に判定された権限名を集める。
     *
     * @return array<int, string>
     */
    private function abilitiesCheckedDuring(callable $request): array
    {
        $checked = [];

        // Gate::after は判定のあとに必ず呼ばれる。null を返すと元の判定結果を変えない
        Gate::after(function ($user, string $ability) use (&$checked) {
            $checked[] = $ability;

            return null;
        });

        $request();

        return $checked;
    }

    public function test_each_endpoint_consults_the_policy(): void
    {
        // Arrange: すべての遷移が成立する状態を個別に用意する
        $admin = User::factory()->admin()->create();
        $draft = Plan::factory()->draft()->create();
        $published = Plan::factory()->published()->create();
        $archived = Plan::factory()->archived()->create();
        // 削除専用。他のケースの副作用を受けないよう、削除条件を満たす別のプランを用意する
        $deletable = Plan::factory()->draft()->create();

        $this->actingAs($admin);

        $payload = [
            'name' => '検証用プラン',
            'duration_days' => 30,
            'default_meeting_quota' => 4,
        ];

        // エンドポイント => [期待する権限名, リクエスト]
        $cases = [
            'index' => ['viewAny', fn () => $this->get(route('admin.plans.index'))],
            'show' => ['view', fn () => $this->get(route('admin.plans.show', $draft))],
            'create' => ['create', fn () => $this->get(route('admin.plans.create'))],
            'store' => ['create', fn () => $this->post(route('admin.plans.store'), $payload)],
            'edit' => ['update', fn () => $this->get(route('admin.plans.edit', $draft))],
            // 更新は PUT(面談パックの PATCH と違う。支給 edit.blade.php:26 が @method('PUT'))
            'update' => ['update', fn () => $this->put(
                route('admin.plans.update', $draft),
                [...$payload, 'name' => '検証用プラン(更新)'],
            )],
            'publish' => ['publish', fn () => $this->post(route('admin.plans.publish', $draft))],
            'archive' => ['archive', fn () => $this->post(route('admin.plans.archive', $published))],
            'unarchive' => ['unarchive', fn () => $this->post(route('admin.plans.unarchive', $archived))],
            // 削除は専用のプランで試す。unarchive のケースが $archived を下書きに変えてしまうため、
            // 使い回すとケースの順番に結果が左右される
            'destroy' => ['delete', fn () => $this->delete(route('admin.plans.destroy', $deletable))],
        ];

        // Act & Assert
        foreach ($cases as $endpoint => [$ability, $request]) {
            $checked = $this->abilitiesCheckedDuring($request);

            $this->assertContains(
                $ability,
                $checked,
                "{$endpoint} が Policy の {$ability} を判定していない(authorize の書き忘れ)"
            );
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        // Arrange & Act & Assert: ログインしていなければ 403 ではなくログイン画面へ送られる。
        // ルートを admin グループの外へ移されたときに、この行が挙動の変化を教える
        $this->get(route('admin.plans.index'))
            ->assertRedirect(route('login'));
    }
}
