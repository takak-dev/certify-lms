<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * EnrollmentGoal モデルのリレーション・Scope・Cast・判定メソッドを検証する Unit テスト。
 * 1 リレーション (enrollment) + 1 scope (displayOrder) + 2 cast (target_date / achieved_at)
 * + 1 判定メソッド (isAchieved) を網羅する。
 *
 * ⛔ scopeDisplayOrder() と isAchieved() は名前を支給コードが固定している
 * (FetchStudentDashboardAction.php:299 / goal-timeline.blade.php:22)。改名するとダッシュボードが落ちる。
 *
 * 手本: tests/Unit/Models/ChapterTest.php
 */
class EnrollmentGoalTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_relation_returns_parent_enrollment(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        // Act
        $parent = $goal->enrollment;

        // Assert
        $this->assertTrue($parent->is($enrollment));
    }

    /**
     * 親が論理削除されると belongsTo は null を返す。
     *
     * EnrollmentGoalPolicy はこの性質に乗って「解除済みでは操作ボタンを出さない」を実現しているので、
     * ここが変わると認可の振る舞いが黙って変わる。
     */
    public function test_enrollment_relation_returns_null_when_parent_is_soft_deleted(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        // Act
        $enrollment->delete();

        // Assert: 目標の行は残るが、親はたどれない
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id]);
        $this->assertNull(EnrollmentGoal::findOrFail($goal->id)->enrollment);
    }

    /**
     * 一覧の表示順(decisions #43)の1〜3段目。
     * 未達成を先に → 目標期日が近い順 → 期日なしは末尾 → 達成済みは最後。
     */
    public function test_scope_display_order_sorts_by_achievement_then_target_date(): void
    {
        // Arrange: 正しい順とは違う順で作る
        $enrollment = Enrollment::factory()->learning()->create();

        $achieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create();
        $noDate = EnrollmentGoal::factory()->forEnrollment($enrollment)->withoutTargetDate()->create();
        $far = EnrollmentGoal::factory()->forEnrollment($enrollment)
            ->create(['target_date' => now()->addMonths(6)->toDateString()]);
        $near = EnrollmentGoal::factory()->forEnrollment($enrollment)
            ->create(['target_date' => now()->addDay()->toDateString()]);

        // Act
        $ordered = EnrollmentGoal::query()->displayOrder()->pluck('id')->all();

        // Assert
        $this->assertSame([$near->id, $far->id, $noDate->id, $achieved->id], $ordered);
    }

    /**
     * 一覧の表示順(decisions #43)の4段目——同じ目標期日なら、作成日の新しい順に並ぶ。
     *
     * この4段目は面談1 で PM がこちらの案に追加した項目（docs/面談1-想定問答.md:388）。
     * 上位3段だけを見るテストでは、ここを落としても気付けない。
     */
    public function test_scope_display_order_falls_back_to_newest_created_at(): void
    {
        // Arrange: 目標期日をまったく同じにして、作成日時だけずらす
        $enrollment = Enrollment::factory()->learning()->create();
        $sameDate = now()->addMonth()->toDateString();

        $older = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'target_date' => $sameDate,
            'created_at' => now()->subDays(5),
        ]);
        $newer = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'target_date' => $sameDate,
            'created_at' => now()->subDay(),
        ]);

        // Act
        $ordered = EnrollmentGoal::query()->displayOrder()->pluck('id')->all();

        // Assert: 後から立てた方が先に来る
        $this->assertSame([$newer->id, $older->id], $ordered);
    }

    /**
     * target_date は date、achieved_at は datetime にキャストされる。
     *
     * ⚠️ Blade が日付として ->format() を呼ぶので、キャストが外れると文字列に対する
     *    メソッド呼び出しになって画面が落ちる（_form.blade.php:69 / goal-timeline.blade.php:48）。
     *    ダッシュボードの残日数計算（goal-timeline.blade.php:26 の diffInDays）も Carbon が前提。
     */
    public function test_date_columns_are_cast_to_carbon(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'target_date' => '2026-12-31',
        ]);
        $goal->forceFill(['achieved_at' => '2026-09-14 10:00:00'])->save();

        // Act
        $fresh = EnrollmentGoal::findOrFail($goal->id);

        // Assert
        $this->assertInstanceOf(Carbon::class, $fresh->target_date);
        $this->assertInstanceOf(Carbon::class, $fresh->achieved_at);
        $this->assertSame('2026-12-31', $fresh->target_date->format('Y-m-d'));
        $this->assertSame('2026-09-14 10:00:00', $fresh->achieved_at->format('Y-m-d H:i:s'));
    }

    /** 達成状態は achieved_at の有無だけで決まる（boolean 列は持たない） */
    public function test_is_achieved_reflects_achieved_at(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        // Assert: 未達成
        $this->assertFalse($goal->isAchieved());

        // Act & Assert: 達成日時を入れると true
        $goal->forceFill(['achieved_at' => now()])->save();
        $this->assertTrue($goal->fresh()->isAchieved());
    }

    /**
     * 達成日時とタイトル以外は一括代入できない。
     *
     * achieved_at は達成マーク専用エンドポイントの担当、enrollment_id は URL から決まるので、
     * どちらもフォーム経由で書き込ませない（$fillable に含めない）。
     */
    public function test_fillable_excludes_achieved_at_and_foreign_key(): void
    {
        // Arrange & Act: 禁止列を一括代入で渡してみる
        $goal = new EnrollmentGoal([
            'title' => '一括代入の検証',
            'achieved_at' => '2020-01-01 00:00:00',
            'enrollment_id' => 'NOT_MY_ENROLLMENT',
        ]);

        // Assert: title だけが入る
        $this->assertSame('一括代入の検証', $goal->title);
        $this->assertNull($goal->achieved_at);
        $this->assertNull($goal->enrollment_id);
    }
}
