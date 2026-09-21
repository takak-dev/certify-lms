<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\GoogleCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GoogleCredential が秘密情報を配列 / JSON へ出さないことを固定する(S-A-01)。
 *
 * ⚠️ いまは Model を JSON で返す動線が無いため、画面のテストでは検知できない
 *    （支給 Blade はそもそもトークンを出さないので、$hidden を外しても緑のまま）。
 *    しかし S-A-05（Sanctum + JS フロント）で Model が JSON になる動線が増えるため、
 *    **そのときにトークンが漏れる口**をここで塞いでおく。リポジトリは PUBLIC。
 */
class GoogleCredentialTest extends TestCase
{
    use RefreshDatabase;

    /** 配列化するとトークンが落ちる。 */
    public function test_tokens_are_hidden_from_array_conversion(): void
    {
        // Arrange
        $credential = GoogleCredential::factory()->create([
            'access_token' => 'SECRET-ACCESS-TOKEN',
            'refresh_token' => 'SECRET-REFRESH-TOKEN',
        ]);

        // Act
        $array = $credential->toArray();

        // Assert
        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('refresh_token', $array);
        // 画面が使う項目は残っていること（隠しすぎていない）
        $this->assertArrayHasKey('calendar_id', $array);
        $this->assertArrayHasKey('connected_at', $array);
    }

    /** JSON 化でも同じ。 */
    public function test_tokens_are_hidden_from_json_conversion(): void
    {
        // Arrange
        $credential = GoogleCredential::factory()->create([
            'access_token' => 'SECRET-ACCESS-TOKEN',
            'refresh_token' => 'SECRET-REFRESH-TOKEN',
        ]);

        // Act & Assert
        $json = $credential->toJson();
        $this->assertStringNotContainsString('SECRET-ACCESS-TOKEN', $json);
        $this->assertStringNotContainsString('SECRET-REFRESH-TOKEN', $json);
    }
}
