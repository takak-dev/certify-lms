<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\MockExam;

use App\Http\Requests\MockExam\UpdateRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 模試マスタ更新 UpdateRequest の rules() バリデーション検証。
 * title / description / order / passing_score の数値レンジ・文字数を Validator::make で網羅する。
 */
class UpdateRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_with_valid_payload(): void
    {
        // Arrange
        $payload = ['title' => '基本情報模試 第2回', 'description' => '解説付き', 'order' => 1, 'passing_score' => 60];

        // Act
        $validator = Validator::make($payload, (new UpdateRequest)->rules());

        // Assert
        $this->assertTrue($validator->passes(), $validator->errors()->toJson());
    }

    /**
     * 上限・下限の「ちょうどの値」が通ることを固定する。
     *
     * 既存の異常系は 0 と 101 を弾くことしか見ていないため、仮に max:99 と書き間違えても
     * (101 は落ち 60 は通るので)全テストが緑のまま素通りしてしまう。境界そのものを押さえる。
     */
    #[DataProvider('boundaryPassingScores')]
    public function test_passes_for_boundary_passing_score(int $passingScore): void
    {
        // Arrange: 合格点だけを境界値に差し替え、他の項目は正常系と同じにする
        $payload = ['title' => '基本情報模試 第2回', 'order' => 1, 'passing_score' => $passingScore];

        // Act
        $validator = Validator::make($payload, (new UpdateRequest)->rules());

        // Assert
        $this->assertTrue($validator->passes(), $validator->errors()->toJson());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function boundaryPassingScores(): array
    {
        return [
            '下限 1' => [1],
            '上限 100' => [100],
        ];
    }

    #[DataProvider('invalidCases')]
    public function test_fails_for_invalid_field(string $field, mixed $value): void
    {
        // Arrange
        $payload = array_merge(['title' => 'Sample', 'order' => 0, 'passing_score' => 60], [$field => $value]);

        // Act
        $validator = Validator::make($payload, (new UpdateRequest)->rules());

        // Assert
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey($field, $validator->errors()->toArray());
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidCases(): array
    {
        return [
            'title 未指定で エラー' => ['title', ''],
            'title 101 文字で エラー' => ['title', str_repeat('a', 101)],
            'description 2001 文字で エラー' => ['description', str_repeat('b', 2001)],
            'order 負数で エラー' => ['order', -1],
            'order 65536 で エラー' => ['order', 65536],
            'passing_score 0 で エラー' => ['passing_score', 0],
            'passing_score 101 で エラー' => ['passing_score', 101],
        ];
    }
}
