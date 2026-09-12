<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * 10 本のエンドポイントが「本当に Policy を呼んでいるか」を検証する。
 *
 * ⚠️ なぜこのテストが要るのか。
 * 他のテストにある「受講生・コーチは 403」は、実は Policy が無くても通る。
 * ルートが role:admin グループの中にあるので、ミドルウェアが先に 403 を返してしまうからだ。
 * つまり Controller の $this->authorize() を 10 本すべて消しても、それらのテストは緑のまま。
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
        $draft = MeetingPack::factory()->draft()->create();
        $published = MeetingPack::factory()->published()->create();
        $archived = MeetingPack::factory()->archived()->create();

        $this->actingAs($admin);

        // エンドポイント => [期待する権限名, リクエスト]
        $cases = [
            'index' => ['viewAny', fn () => $this->get(route('admin.meeting-packs.index'))],
            'show' => ['view', fn () => $this->get(route('admin.meeting-packs.show', $draft))],
            'create' => ['create', fn () => $this->get(route('admin.meeting-packs.create'))],
            'store' => ['create', fn () => $this->post(route('admin.meeting-packs.store'), [
                'name' => '検証用パック',
                'meeting_count' => 1,
                'price' => 1000,
            ])],
            'edit' => ['update', fn () => $this->get(route('admin.meeting-packs.edit', $draft))],
            'update' => ['update', fn () => $this->patch(route('admin.meeting-packs.update', $draft), [
                'name' => '検証用パック(更新)',
                'meeting_count' => 2,
                'price' => 2000,
            ])],
            'publish' => ['publish', fn () => $this->post(route('admin.meeting-packs.publish', $draft))],
            'archive' => ['archive', fn () => $this->post(route('admin.meeting-packs.archive', $published))],
            'unarchive' => ['unarchive', fn () => $this->post(route('admin.meeting-packs.unarchive', $archived))],
            // 削除は最後。ここまでで消えていないパックを使う
            'destroy' => ['delete', fn () => $this->delete(route('admin.meeting-packs.destroy', $archived))],
        ];

        // Act & Assert
        foreach ($cases as $endpoint => [$ability, $request]) {
            $checked = $this->abilitiesCheckedDuring($request);

            $this->assertContains(
                $ability,
                $checked,
                "{$endpoint} が Policy の {$ability} を判定していない（authorize の書き忘れ）"
            );
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        // Arrange & Act & Assert: ログインしていなければ 403 ではなくログイン画面へ送られる。
        // ルートを admin グループの外へ移されたときに、この行が挙動の変化を教える
        $this->get(route('admin.meeting-packs.index'))
            ->assertRedirect(route('login'));
    }
}
