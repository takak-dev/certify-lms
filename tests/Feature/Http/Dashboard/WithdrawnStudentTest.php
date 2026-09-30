<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Dashboard;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\UserWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 担当受講生が退会したあとも、コーチのダッシュボードが開けることを確かめる(decisions #284)。
 *
 * 退会(UserWithdrawalService)は users を論理削除するだけで、受講登録(enrollments)は受講中のまま残る。
 * Enrollment::user() が論理削除済みのユーザーを引かないと、「担当受講生」の一覧
 * (dashboard/_partials/coach/assigned-students-list.blade.php の `$enrollment->user->name`)が 500 になる。
 * 修正は Enrollment::user() の withTrashed で、退会者は氏名付きで一覧に残る
 * (退会者を一覧から外すかは「退会時に受講登録を閉じるか」の設計判断で、この修正の範囲外)。
 */
class WithdrawnStudentTest extends TestCase
{
    use RefreshDatabase;

    public function test_coach_dashboard_opens_after_assigned_student_withdraws(): void
    {
        // Arrange: コーチが担当する資格に、受講中の受講生が 1 人いる。
        //   ダッシュボードの「担当受講生」は、担当資格の受講中・修了の受講登録を一覧にする。
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        // 担当の割り当て。書き方は FetchCoachDashboardActionTest::attachCoach() と同じ。
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        Enrollment::factory()->for($student)->for($certification)->learning()->create();

        // 退会は本物の処理で行う(factory で status だけ変えると、論理削除が起きず再現しない)。
        app(UserWithdrawalService::class)->withdraw($student);

        // Act: 担当コーチがダッシュボードを開く。
        $response = $this->actingAs($coach)->get(route('dashboard.index'));

        // Assert: 500 にならず開け、退会者も氏名付きで一覧に出る。
        //   withTrashed を外すと 500 になり assertOk() で落ちる。氏名まで見るのは、別の直し方
        //   (Blade で `?->name ?? '-'` と null を防ぐだけ)をされた場合に、500 は消えても氏名が出なくなり、
        //   こちらの行で落ちるようにするため(退会処理はメールアドレスを書き換えるだけで、氏名は変えない)。
        $response->assertOk();
        $response->assertSee($student->name);
    }
}
