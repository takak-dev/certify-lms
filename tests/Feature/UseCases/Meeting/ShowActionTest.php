<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\Models\User;
use App\UseCases\Meeting\ShowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 面談詳細の表示に必要なリレーションを揃える ShowAction の検証(T-A-02)。
 *
 * この Action の仕事は 1 つだけ —— Blade が参照するリレーションを読み込むこと。
 * loadMissing() への指定は 5 つだが、enrollment.certification が入れ子なので実体は 6 リレーション。
 * 1 つでも欠けると詳細画面が N+1 になるか、未読込のリレーションを参照して落ちる。
 */
class ShowActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_loads_all_relations_the_detail_view_needs(): void
    {
        // --- Arrange ---
        // canceledBy と meetingMemo も埋まった面談を作る。
        // 「キャンセル済み + メモあり」は 6 リレーションすべてに値が入る唯一の状態で、
        // null のせいで読み込み漏れを見逃すことがない
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->canceled()->forCoach($coach)->forStudent($student)->create([
            'canceled_by_user_id' => $student->id,
        ]);
        MeetingMemo::factory()->forMeeting($meeting)->create();

        // Route Model Binding 直後の状態を再現する。何も読み込まれていないところから始める
        $fresh = Meeting::query()->findOrFail($meeting->id);

        // --- Act ---
        $result = app(ShowAction::class)($fresh);

        // --- Assert: Blade が使う 6 リレーションがすべて読み込み済みであること ---
        // relationLoaded() は「そのリレーションを既に取得したか」を返す Eloquent のメソッド。
        // 値の中身ではなく「追加クエリなしで参照できる状態か」を見ている
        $this->assertTrue($result->relationLoaded('enrollment'));
        $this->assertTrue($result->enrollment->relationLoaded('certification'));
        $this->assertTrue($result->relationLoaded('coach'));
        $this->assertTrue($result->relationLoaded('student'));
        $this->assertTrue($result->relationLoaded('canceledBy'));
        $this->assertTrue($result->relationLoaded('meetingMemo'));
    }

    public function test_returns_the_same_instance_it_was_given(): void
    {
        // --- Arrange ---
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create();

        // --- Act ---
        $result = app(ShowAction::class)($meeting);

        // --- Assert ---
        // loadMissing() は新しいモデルを作らず自分自身を返す。Controller は戻り値をそのまま
        // view に渡すので、別インスタンスにすり替わっていないことを担保しておく
        // (assertSame は「同じ値」ではなく「同じ実体か」を見る)
        $this->assertSame($meeting, $result);
    }
}
