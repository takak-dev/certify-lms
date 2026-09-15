<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certificate;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\UseCases\CertificationCoachAssignment\DetachAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 修了証 PDF のダウンロード(GET /certificates/{certificate}/download)の検証。S-A-04。
 *
 * 検証の軸は 3 つ。
 * 1. 認可: 本人 / 担当コーチ / 管理者は可、他受講生 / 担当外コーチは不可(CertificatePolicy::download)
 * 2. ステータス非依存: 修了(graduated)した受講生でも本人の修了証は取得できる(修了証は永続資産)
 * 3. 実体の有無: PDF が保管領域に無ければ 404(原典「見つからない場合はダウンロードできない」)
 *
 * PDF の中身は検証しない。それは CertificatePdfService の責務で、ここでは「配信と認可」だけを見る。
 * そのため実体はダミーの文字列を置いている(mPDF を毎回動かすとテストが遅くなる)。
 */
class DownloadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 資格の担当コーチに割り当てる。
     *
     * pivot(certification_coach_assignments)は ULID 主キーと割当者を要求するため、
     * attach に 3 列を渡す必要がある。手本: tests/Feature/Http/EnrollmentNote/DestroyTest.php
     */
    private function assignCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);
    }

    /**
     * 「修了済の受講登録 + 修了証 + PDF 実体」を一式作る。
     *
     * @param Certification|null $certification 担当コーチを固定したい場合に渡す
     */
    private function certificateWithPdf(User $student, ?Certification $certification = null): Certificate
    {
        $certification ??= Certification::factory()->published()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->passed()
            ->create();

        $certificate = Certificate::factory()->forEnrollment($enrollment)->create();

        // ダウンロードできる状態にするため実体を置く(中身は問わない)。
        Storage::disk('private')->put($certificate->pdf_path, '%PDF-1.4 dummy');

        return $certificate;
    }

    public function test_student_can_download_own_certificate(): void
    {
        // Arrange: 学習中の受講生と、その本人の修了証
        Storage::fake('private');
        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->certificateWithPdf($student);

        // Act
        $response = $this->actingAs($student)->get(route('certificates.download', $certificate));

        // Assert: 200 かつ「添付ファイル」として配信される
        //         (原典「ダウンロードはファイル添付形式で配信する」= Content-Disposition: attachment)
        $response->assertOk();
        $response->assertDownload();
    }

    /**
     * ⭐ このテストはルートのミドルウェアの番人。
     *
     * routes/web.php の certificates.download に `active-learning` を足すと、修了者が
     * EnsureActiveLearning に弾かれてここが 403 で落ちる。修了者ダッシュボードは
     * 修了証 PDF ボタンしか持たないため、それは「修了証を取得する手段の喪失」を意味する。
     */
    public function test_graduated_student_can_still_download_own_certificate(): void
    {
        // Arrange: 修了(graduated)した受講生。学習機能からは締め出されている状態
        Storage::fake('private');
        $student = User::factory()->student()->graduated()->create();
        $certificate = $this->certificateWithPdf($student);

        // Act
        $response = $this->actingAs($student)->get(route('certificates.download', $certificate));

        // Assert: 修了証は永続資産なので取得できる
        $response->assertOk();
        $response->assertDownload();
    }

    public function test_other_student_cannot_download(): void
    {
        // Arrange: 修了証の持ち主とは別の受講生
        Storage::fake('private');
        $owner = User::factory()->student()->inProgress()->create();
        $certificate = $this->certificateWithPdf($owner);
        $otherStudent = User::factory()->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($otherStudent)->get(route('certificates.download', $certificate));

        // Assert: 他人の修了証は個人情報。403
        $response->assertForbidden();
    }

    public function test_assigned_coach_can_download(): void
    {
        // Arrange: 修了証の資格を担当しているコーチ
        Storage::fake('private');
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);

        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->certificateWithPdf($student, $certification);

        // Act
        $response = $this->actingAs($coach)->get(route('certificates.download', $certificate));

        // Assert: 担当資格の修了証は取得できる(卒業生のサポート資料として保管する用途)
        $response->assertOk();
        $response->assertDownload();
    }

    public function test_unassigned_coach_cannot_download(): void
    {
        // Arrange: 修了証の資格を担当していないコーチ
        //          (別の資格の担当に割り当てて「コーチではあるが担当外」を作る)
        Storage::fake('private');
        $certification = Certification::factory()->published()->create();
        $anotherCertification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($anotherCertification, $coach);

        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->certificateWithPdf($student, $certification);

        // Act
        $response = $this->actingAs($coach)->get(route('certificates.download', $certificate));

        // Assert: 他コーチの担当領域のプライバシーを尊重する(原典のユーザーストーリー)
        $response->assertForbidden();
    }

    /**
     * ⭐ decisions #173 の核心（担当を外れたら取得できなくなる）の番人。
     *
     * 「別資格の担当コーチ」を使う上のテストでは、`Certification::coaches()` の
     * `wherePivot('unassigned_at', null)`（app/Models/Certification.php:89）を外しても落ちない。
     * 解除済みコーチで確かめて初めて、その一行が守られる。
     */
    public function test_coach_unassigned_from_certification_cannot_download(): void
    {
        // Arrange: いったん担当に付けたコーチを、実際の解除ユースケースで外す
        Storage::fake('private');
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);

        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->certificateWithPdf($student, $certification);

        // pivot を直接いじらず、本番と同じ経路（unassigned_at をセットし履歴行は残す）で解除する
        app(DetachAction::class)($certification, $coach, User::factory()->admin()->create());

        // Act
        $response = $this->actingAs($coach)->get(route('certificates.download', $certificate));

        // Assert: 担当を外れた時点で、過去に担当していた資格の修了証にも触れない
        $response->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        // Arrange: ログインしていない状態
        Storage::fake('private');
        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->certificateWithPdf($student);

        // Act: actingAs を付けずに叩く
        $response = $this->get(route('certificates.download', $certificate));

        // Assert: ルートから `auth` ミドルウェアが外れたらここが落ちる
        $response->assertRedirect(route('login'));
    }

    public function test_admin_can_download_any_certificate(): void
    {
        // Arrange: 管理者と、無関係な受講生の修了証
        Storage::fake('private');
        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->certificateWithPdf($student);
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->get(route('certificates.download', $certificate));

        // Assert: 運用の問い合わせ対応 / 監査のため全件参照できる
        $response->assertOk();
        $response->assertDownload();
    }

    public function test_returns_404_when_pdf_file_is_missing(): void
    {
        // Arrange: certificates の行はあるが PDF の実体を置かない。
        //          S-A-04 より前に発行された既存データがこの状態にあたる。
        Storage::fake('private');
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->passed()->create();
        $certificate = Certificate::factory()->forEnrollment($enrollment)->create();

        // Act: 認可は通るが実体が無い
        $response = $this->actingAs($student)->get(route('certificates.download', $certificate));

        // Assert: 空ファイルや壊れた PDF を返さず 404
        $response->assertNotFound();
    }
}
