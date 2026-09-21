<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GoogleCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleCredential>
 */
class GoogleCredentialFactory extends Factory
{
    protected $model = GoogleCredential::class;

    /**
     * 既定は「連携したばかりのコーチ」。
     *
     * 所有者を明示しない呼び出しでも role が coach になるようにして、受講生や管理者が
     * 連携している本番ではありえない行ができないようにする(手本: EnrollmentNoteFactory::definition())。
     *
     * ⚠️ トークンはダミー文字列。実際の Google トークンとは形式が違うが、テストでは
     *    Google API 自体をモックするので値の中身は問われない(本格的なモックは T-A-04)。
     *    「本物らしい値」を置くと秘密スキャン(gitleaks)の誤検知を招くので、
     *    先頭に dummy- を付けて明らかにダミーと分かる形にしている。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->coach(),
            // 原典 スコープ外「連携カレンダーの選択 UI — プライマリカレンダー固定」により実値は常にこれ。
            'calendar_id' => 'primary',
            'access_token' => 'dummy-access-token-'.fake()->uuid(),
            'refresh_token' => 'dummy-refresh-token-'.fake()->uuid(),
            // Google のアクセストークンの既定有効期間は 1 時間。
            'expires_at' => now()->addHour(),
            'connected_at' => now(),
        ];
    }

    /**
     * 連携しているコーチを指定する。手本: CoachAvailabilityFactory::forCoach()。
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * アクセストークンが失効済みの状態。
     *
     * 「失効していたら refresh_token で更新してから API を呼ぶ」という、原典 共通の振る舞い
     * 「連携は一度設定すれば継続して使える状態を保つ」の検証に使う。
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * リフレッシュトークンを持たない状態。
     *
     * Google は 2 回目以降の認可では refresh_token を返さないため、実際に起こりうる状態
     * (公式ドキュメント「the refresh token is only returned ... in the initial request」)。
     * この状態でアクセストークンが切れると更新できず、連携が実質切れることの検証に使う。
     */
    public function withoutRefreshToken(): static
    {
        return $this->state(fn (): array => [
            'refresh_token' => null,
        ]);
    }
}
