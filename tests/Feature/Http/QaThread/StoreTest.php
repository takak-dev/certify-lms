<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 質問スレッドの投稿（POST /qa-board）の検証。
 *
 * 守りたいのは「誰が投稿できるか」と「サーバが決める値をフォームから奪えないか」。
 * 投稿者・状態はサーバ側で確定するため、リクエストに混ぜても無視されることを固定する。
 */
class StoreTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> 有効な入力の雛形 */
    private function validPayload(Certification $certification): array
    {
        return [
            'certification_id' => $certification->id,
            'title' => 'データベース設計について質問です',
            'body' => '正規化の手順で迷っています。考え方の順序を教えてください。',
        ];
    }

    public function test_student_can_post_thread_and_is_redirected_to_detail(): void
    {
        // Arrange
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)->post(route('qa-board.store'), $this->validPayload($certification));

        // Assert: 作成後は詳細へ遷移し、成功メッセージが出る（_共通ルール.md §1）
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('qa_threads', [
            'user_id' => $student->id,
            'certification_id' => $certification->id,
            'title' => 'データベース設計について質問です',
            'status' => QaThreadStatus::Open->value,
            'resolved_at' => null,
        ]);
    }

    public function test_student_can_post_to_certification_they_are_not_enrolled_in(): void
    {
        // Arrange: 受講登録を作らない。原典「受講生は公開済資格すべてのスレッドを閲覧・投稿できる」
        // （create.blade.php:32 hint「受講していない資格でも質問できます。」）
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)->post(route('qa-board.store'), $this->validPayload($certification));

        // Assert
        $response->assertSessionHas('success');
        $this->assertDatabaseCount('qa_threads', 1);
    }

    public function test_coach_and_admin_cannot_post_thread(): void
    {
        // Arrange: スレッドを立てられるのは受講生のみ（原典「スレッドの管理(投稿は受講生のみ)」）
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        // Act & Assert: コーチは Policy で 403、管理者はルートのミドルウェアで 403
        $this->actingAs($coach)->post(route('qa-board.store'), $this->validPayload($certification))->assertForbidden();
        $this->actingAs($admin)->post(route('qa-board.store'), $this->validPayload($certification))->assertForbidden();
        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_unpublished_certification_is_rejected(): void
    {
        // Arrange: 画面のセレクトには公開中しか出ないが、値は手で差し替えられる。
        // exists だけでは status を確認できないため、Rule::exists()->where() で弾いている
        $archived = Certification::factory()->archived()->create();
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)->post(route('qa-board.store'), $this->validPayload($archived));

        // Assert
        $response->assertSessionHasErrors('certification_id');
        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_required_fields_are_validated(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        // Act: 3項目とも空で送る
        $response = $this->actingAs($student)->post(route('qa-board.store'), [
            'certification_id' => '',
            'title' => '',
            'body' => '',
        ]);

        // Assert
        $response->assertSessionHasErrors(['certification_id', 'title', 'body']);
    }

    public function test_title_and_body_length_limits_follow_the_form_attributes(): void
    {
        // Arrange: 上限は支給 Blade の maxlength に合わせている（title 200 / body 5000）
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();

        // Act & Assert: 上限ちょうどは通る（セッションは毎リクエストで上書きされるため、都度検証する）
        $this->actingAs($student)->post(route('qa-board.store'), [
            'certification_id' => $certification->id,
            'title' => str_repeat('あ', 200),
            'body' => str_repeat('い', 5000),
        ])->assertSessionHas('success');

        // Act & Assert: 1文字でも超えると弾かれる（境界値）
        $this->actingAs($student)->post(route('qa-board.store'), [
            'certification_id' => $certification->id,
            'title' => str_repeat('あ', 201),
            'body' => str_repeat('い', 5001),
        ])->assertSessionHasErrors(['title', 'body']);
    }

    public function test_author_and_status_cannot_be_forged_through_the_form(): void
    {
        // Arrange: 投稿者と状態はサーバが決める。フォームに混ぜても効かないことを固定する
        // （user_id / status / resolved_at は $fillable に含めず、Action が明示代入する）
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();
        $someoneElse = User::factory()->student()->create();

        // Act: 他人の ID と「解決済」を紛れ込ませて送る
        $this->actingAs($student)->post(route('qa-board.store'), $this->validPayload($certification) + [
            'user_id' => $someoneElse->id,
            'status' => QaThreadStatus::Resolved->value,
            'resolved_at' => now()->toDateTimeString(),
        ]);

        // Assert: 投稿者はログイン中の本人、状態は未解決のまま
        $this->assertDatabaseHas('qa_threads', [
            'user_id' => $student->id,
            'status' => QaThreadStatus::Open->value,
            'resolved_at' => null,
        ]);
        $this->assertDatabaseMissing('qa_threads', ['user_id' => $someoneElse->id]);
    }
}
