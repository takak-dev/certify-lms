<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\EnrollmentStatus;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * 配信の実行（POST /admin/announcements）の検証。本チケットの本体。
 *
 * 見るのは 4 つ——①誰に届くか ②配信実績が正しく記録されるか
 * ③入力の組み合わせをどう扱うか（decisions #88）④誰が配信できるか。
 */
class StoreTest extends TestCase
{
    use RefreshDatabase;

    /** 正常な入力の雛形。各テストで必要な項目だけ差し替える */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'システムメンテナンス実施のお知らせ',
            'body' => "下記の日程で作業を行います。\n\nご不便をおかけします。",
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ], $overrides);
    }

    public function test_all_students_receives_only_in_progress_students(): void
    {
        // Arrange: 受講中の受講生 2 人のほか、届いてはいけない 3 人を混ぜる
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $a = User::factory()->student()->inProgress()->create();
        $b = User::factory()->student()->inProgress()->create();
        $graduated = User::factory()->student()->graduated()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        // Act
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload());

        // Assert: 受講中の受講生だけに届き、配信件数もその人数になる
        Notification::assertSentTo([$a, $b], AdminAnnouncementNotification::class);
        Notification::assertNotSentTo([$graduated, $coach, $admin], AdminAnnouncementNotification::class);
        $this->assertSame(2, Announcement::firstOrFail()->dispatched_count);
    }

    /**
     * 資格指定は「その資格に受講登録がある受講生」。受講登録の状態では絞らない（decisions #91）。
     * 原典のスコープ外「配信ターゲット内の追加絞り込み（学習進捗フィルタ等）」に触れないため。
     */
    public function test_certification_target_includes_every_enrollment_status(): void
    {
        // Arrange: 同じ資格に 3 つの状態で受講登録している受講生と、別資格の受講生
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $target = Certification::factory()->create();
        $other = Certification::factory()->create();

        $learning = $this->studentEnrolledIn($target, EnrollmentStatus::Learning);
        $passed = $this->studentEnrolledIn($target, EnrollmentStatus::Passed);
        $failed = $this->studentEnrolledIn($target, EnrollmentStatus::Failed);
        $unrelated = $this->studentEnrolledIn($other, EnrollmentStatus::Learning);

        // Act
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $target->id,
        ]));

        // Assert
        Notification::assertSentTo([$learning, $passed, $failed], AdminAnnouncementNotification::class);
        Notification::assertNotSentTo([$unrelated], AdminAnnouncementNotification::class);
        $this->assertSame(3, Announcement::firstOrFail()->dispatched_count);
    }

    public function test_user_target_reaches_only_that_student(): void
    {
        // Arrange
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $target = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();

        // Act
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => $target->id,
        ]));

        // Assert
        Notification::assertSentTo([$target], AdminAnnouncementNotification::class);
        Notification::assertNotSentTo([$other], AdminAnnouncementNotification::class);
        $this->assertSame(1, Announcement::firstOrFail()->dispatched_count);
    }

    /**
     * 対象 0 件でも配信は成功扱い（decisions #49）。誤操作の証跡として履歴に残す。
     */
    public function test_zero_recipients_still_records_the_dispatch(): void
    {
        // Arrange: 受講生が 1 人もいない状態
        Notification::fake();
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload());

        // Assert: エラーにせず、件数 0 で記録する
        $response->assertSessionHas('success');
        $announcement = Announcement::firstOrFail();
        $this->assertSame(0, $announcement->dispatched_count);
        $this->assertNotNull($announcement->dispatched_at);
    }

    /**
     * ⭐ decisions #88。配信対象タイプに対応しない欄の値は、エラーにせず捨てる。
     *
     * 支給フォームは 2 つのセレクトを常時表示し、表示切替の JS を持たない。
     * 「資格指定 → 全受講生」とラジオを変えても資格の選択は残るため、
     * それをエラーにすると正しい操作の邪魔になる。
     */
    public function test_values_for_the_unused_target_field_are_discarded(): void
    {
        // Arrange: 全受講生あてなのに、資格と受講生の両方が選ばれたまま送信される
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->create();

        // Act
        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
            'target_certification_id' => $certification->id,
            'target_user_id' => $student->id,
        ]));

        // Assert: 配信は成功し、使わない 2 つの欄は保存されない
        $response->assertSessionHasNoErrors();
        $announcement = Announcement::firstOrFail();
        $this->assertNull($announcement->target_certification_id);
        $this->assertNull($announcement->target_user_id);
        $this->assertSame(AnnouncementTargetType::AllStudents, $announcement->target_type);
    }

    /**
     * ⭐ decisions #88 のもう半分。配信後は詳細画面へ送り、フラッシュに対象と件数を出す。
     * ラジオの押し間違いに配信直後に気づけるようにするため。
     */
    public function test_redirects_to_the_detail_page_with_the_target_and_the_count(): void
    {
        // Arrange
        Notification::fake();
        $admin = User::factory()->admin()->create();
        User::factory()->count(2)->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload());

        // Assert
        $announcement = Announcement::firstOrFail();
        $response->assertRedirect(route('admin.announcements.show', $announcement));
        $response->assertSessionHas('success', fn (string $message): bool => str_contains($message, '全受講生')
            && str_contains($message, '2 件'));
    }

    public function test_required_fields_are_validated(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act & Assert
        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), ['title' => '', 'body' => '', 'target_type' => ''])
            ->assertSessionHasErrors(['title', 'body', 'target_type']);
    }

    public function test_length_limits_match_the_form_attributes(): void
    {
        // Arrange: 支給 Blade の maxlength は title=200 / body=5000
        $admin = User::factory()->admin()->create();

        // Act & Assert
        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), $this->payload([
                'title' => str_repeat('あ', 201),
                'body' => str_repeat('い', 5001),
            ]))
            ->assertSessionHasErrors(['title', 'body']);
    }

    public function test_target_id_is_required_for_the_matching_type(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act & Assert: 資格指定なのに資格が空
        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), $this->payload([
                'target_type' => AnnouncementTargetType::Certification->value,
            ]))
            ->assertSessionHasErrors('target_certification_id');

        // Act & Assert: ユーザー指定なのに受講生が空
        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), $this->payload([
                'target_type' => AnnouncementTargetType::User->value,
            ]))
            ->assertSessionHasErrors('target_user_id');
    }

    /**
     * 受講中でない受講生・コーチはユーザー指定の対象にできない（decisions #48）。
     * 選べてしまうと「送ったのに届かない」ことになる。
     */
    public function test_only_in_progress_students_can_be_targeted_by_user(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $graduated = User::factory()->student()->graduated()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        foreach ([$graduated, $coach] as $notAllowed) {
            // Act & Assert
            $this->actingAs($admin)
                ->post(route('admin.announcements.store'), $this->payload([
                    'target_type' => AnnouncementTargetType::User->value,
                    'target_user_id' => $notAllowed->id,
                ]))
                ->assertSessionHasErrors('target_user_id');
        }

        $this->assertSame(0, Announcement::count());
    }

    /**
     * ⭐ 入力検証と配信集合の定義が同じ集合を指していることを検査する。
     *
     * 2 箇所に同じ条件が書かれている——StoreRequest の Rule::exists（素のクエリ）と、
     * AnnouncementRecipientService が使う User::scopeInProgressStudents（Eloquent）。
     * 書き方が違うので片方だけ直されうる。ずれると「フォームで選べるのに配信されない」、
     * あるいは「弾かれるべき相手が通る」が起きる。
     *
     * 期待値をリテラルで書かず、スコープの判定と検証の通過を突き合わせているのは、
     * 条件そのものが変わったときにも一致を保証するため。
     */
    public function test_user_target_validation_matches_the_recipient_scope(): void
    {
        // Arrange: 状態の違う相手を一通り用意する
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $withdrawn = User::factory()->student()->inProgress()->create();
        $withdrawn->delete(); // 退会は論理削除

        // UserStatus の 4 状態（invited / in_progress / graduated / withdrawn）を
        // すべて候補に入れる。状態次元を閉じておかないと、配信対象の条件が
        // 片側だけ広がる変更（例: スコープに invited を足す）をこの検査が素通りする
        $candidates = [
            '受講中の受講生' => User::factory()->student()->inProgress()->create(),
            '招待中の受講生' => User::factory()->student()->invited()->create(),
            '修了した受講生' => User::factory()->student()->graduated()->create(),
            '退会状態の受講生' => User::factory()->student()->withdrawn()->create(),
            '受講中のコーチ' => User::factory()->coach()->inProgress()->create(),
            '管理者' => User::factory()->admin()->create(),
            '論理削除された受講生' => $withdrawn,
        ];

        foreach ($candidates as $label => $user) {
            // Act: 配信集合の定義がこの相手を含むか（= 実際に通知が届く相手か）
            $isRecipient = User::query()->inProgressStudents()->whereKey($user->id)->exists();

            $response = $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
                'target_type' => AnnouncementTargetType::User->value,
                'target_user_id' => $user->id,
            ]));

            // Assert: 届く相手なら通り、届かない相手なら弾かれる。
            // assertSessionHasNoErrors() は引数を取らず、assertSessionHasErrors() の第2引数は書式指定なので
            // (TestResponse.php:1338,1397)、どの候補でずれたかを伝えるには $message を持つ assert を使う
            $rejected = $response->getSession()->has('errors');

            $this->assertSame(
                ! $isRecipient,
                $rejected,
                $isRecipient
                    ? "{$label}: 配信対象なのにバリデーションで弾かれました"
                    : "{$label}: 配信対象外なのにバリデーションを通過しました",
            );
        }
    }

    /**
     * 配信対象タイプに文字列以外を送っても 500 にならないこと。
     *
     * prepareForValidation() は認可判定より先に走る（ValidatesWhenResolvedTrait.php:19-22）ため、
     * ここに来る値は Policy をまだ通っていない。型を信用して (string) にすると
     * 「Array to string conversion」の警告が ErrorException になり 500 で落ちる。
     * 現状はルートの role:admin でしか守られていないので、入力側で落とす。
     */
    public function test_non_string_target_type_is_rejected_as_a_validation_error(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act: 配列で送る（target_type[]=all_students に相当）
        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
            'target_type' => [AnnouncementTargetType::AllStudents->value],
        ]));

        // Assert: 500 ではなく通常のバリデーションエラーとして扱う
        $response->assertSessionHasErrors('target_type');
        $this->assertSame(0, Announcement::count());
    }

    public function test_students_and_coaches_cannot_dispatch(): void
    {
        // Arrange
        Notification::fake();
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        foreach ([$student, $coach] as $user) {
            // Act & Assert
            $this->actingAs($user)
                ->post(route('admin.announcements.store'), $this->payload())
                ->assertForbidden();
        }

        $this->assertSame(0, Announcement::count());
        Notification::assertNothingSent();
    }

    /** 指定した資格に、指定した状態で受講登録している受講中の受講生を作る */
    private function studentEnrolledIn(Certification $certification, EnrollmentStatus $status): User
    {
        $student = User::factory()->student()->inProgress()->create();

        Enrollment::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
            'status' => $status->value,
        ]);

        return $student;
    }
}
