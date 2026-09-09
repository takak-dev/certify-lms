<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * コーチダッシュボードの「未回答 質問」カードの検証。
 *
 * このカードは支給コード `Dashboard\FetchCoachDashboardAction` が組み立てるが、
 * `Route::has('qa-board.index')` のガードがあるため S-B-01 でルートを登録するまで一度も動いていない。
 *
 * さらに `HasDashboardSafeFetch::safe()` が例外を握りつぶすため、壊れても 500 にならず
 * 「未回答 Q&A を取得できませんでした。」というフォールバック文言が出るだけになる。
 * 気づける仕組みがここにしか無いので、フォールバックが出ないことを固定する。
 * （実際 S-B-01 の実装中に、Enum のケース名が支給コードと食い違って壊れていた。decisions #70）
 */
class CoachDashboardQaSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function assignCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_coach_dashboard_renders_unanswered_qa_card_without_fallback(): void
    {
        // Arrange: 担当資格に未回答スレッドを1件置く
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);

        QaThread::factory()->forCertification($certification)->create(['title' => '未回答のままの質問']);

        // Act
        $response = $this->actingAs($coach)->get(route('dashboard.index'));

        // Assert: 集計が壊れているとフォールバック文言に置き換わる
        $response->assertOk();
        $response->assertDontSee('未回答 Q&A を取得できませんでした。', false);
        $response->assertSee('未回答のままの質問', false);
    }

    public function test_answered_threads_are_excluded_from_the_card(): void
    {
        // Arrange: 回答が付いたスレッドは「未回答」ではない
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);

        $answered = QaThread::factory()->forCertification($certification)->create(['title' => '回答済みの質問']);
        QaReply::factory()->forThread($answered)->create();

        // Act
        $response = $this->actingAs($coach)->get(route('dashboard.index'));

        // Assert
        $response->assertOk();
        $response->assertDontSee('回答済みの質問', false);
    }

    public function test_threads_of_unpublished_certification_are_not_shown_to_coach(): void
    {
        // Arrange: 担当資格が公開停止になった場合、掲示板では見えなくなる。
        // ダッシュボードのカードだけタイトルと投稿者名が残ると、そこから漏れる
        $archived = Certification::factory()->archived()->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($archived, $coach);

        QaThread::factory()->forCertification($archived)->create(['title' => '公開停止資格の質問']);

        // Act
        $response = $this->actingAs($coach)->get(route('dashboard.index'));

        // Assert
        $response->assertOk();
        $response->assertDontSee('公開停止資格の質問', false);
        $response->assertDontSee('未回答 Q&A を取得できませんでした。', false);
    }
}
