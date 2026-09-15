<?php

declare(strict_types=1);

namespace App\UseCases\Certificate;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Certification\CertificateAlreadyIssuedException;
use App\Exceptions\Certification\CertificatePdfGenerationException;
use App\Exceptions\Certification\EnrollmentNotPassedException;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\CertificatePdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 修了証を発行するユースケース。受講生自己発火型の修了処理 `\App\UseCases\Enrollment\ReceiveCertificateAction` から呼び出される。
 *
 * 業務分岐:
 * - Enrollment が `status=passed` + `passed_at != null` でない: EnrollmentNotPassedException（409）
 * - 同一 Enrollment に対する二重呼出: CertificateAlreadyIssuedException（409、事前 lockForUpdate + exists で検出）
 *
 * 修了証レコードの INSERT と PDF の生成・保存は、同一の `DB::transaction()` 内で実行する。
 * PDF 側が失敗した場合はトランザクションごと ROLLBACK し、「PDF の実体が無いのに certificates の行だけある」
 * 状態を作らない（S-A-04 の要件「PDF 生成に失敗した場合、修了証は発行されていない状態に保つ」）。
 *
 * 例外時は書き込み済みの PDF を削除する（Storage は ROLLBACK で戻らないため）。
 *
 * ⚠️ この後始末が効くのは、本 Action 自身が最外層のトランザクションのときだけ（Seeder / 直呼び / テスト）。
 *    本番の呼び出し元 `ReceiveCertificateAction` は自分の `DB::transaction()` の中で本 Action を呼ぶため、
 *    内側は SAVEPOINT になり COMMIT は最外層でしか起きない。本 Action が正常終了した後に外側の COMMIT が
 *    失敗すると、DB は巻き戻るのにこの catch は通らず PDF だけが残る（実測で確認済み）。
 *    「行が無いのに PDF だけ残る」を完全には防げない。
 */
final class IssueAction
{
    public function __construct(
        private readonly CertificatePdfService $certificatePdf,
    ) {}

    /**
     * @throws EnrollmentNotPassedException 受講登録が修了状態ではない
     * @throws CertificateAlreadyIssuedException 同一 Enrollment で修了証が既発行
     * @throws CertificatePdfGenerationException PDF の生成または保存に失敗した
     */
    public function __invoke(Enrollment $enrollment): Certificate
    {
        if ($enrollment->status !== EnrollmentStatus::Passed || $enrollment->passed_at === null) {
            throw new EnrollmentNotPassedException;
        }

        // 書き込んだ PDF のパスを transaction の外へ持ち出すための入れ物。
        // ROLLBACK しても Storage は巻き戻らないため、失敗時に消す対象を覚えておく必要がある。
        $writtenPdfPath = null;

        try {
            return DB::transaction(function () use ($enrollment, &$writtenPdfPath) {
                // 二重発行ガード: lockForUpdate で同時呼出を直列化し、enrollment_id UNIQUE 違反を例外メッセージ判別ではなく事前 SELECT で確定検出する
                $existing = Certificate::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw new CertificateAlreadyIssuedException;
                }

                $certificate = Certificate::create([
                    'user_id' => $enrollment->user_id,
                    'enrollment_id' => $enrollment->id,
                    'certification_id' => $enrollment->certification_id,
                    'pdf_path' => 'certificates/'.Str::ulid().'.pdf',
                    'issued_at' => now(),
                ]);

                // PDF の生成はレコード作成の後。テンプレートが $certificate（氏名 / 資格名 / 発行日）を参照するため。
                // 生成に失敗すると CertificatePdfGenerationException が飛び、この transaction ごと ROLLBACK される。
                $pdf = $this->certificatePdf->render($certificate);

                // 公開 URL を持たない private disk に保存する（修了証は個人情報。原典「プライベート保管領域」）。
                // ⚠️ config/filesystems.php:45 の private は 'throw' => false なので、書き込みに失敗しても
                //    例外ではなく false が返る。戻り値を見ないと失敗を取りこぼす。
                $writtenPdfPath = $certificate->pdf_path;

                if (Storage::disk('private')->put($certificate->pdf_path, $pdf) === false) {
                    throw new CertificatePdfGenerationException;
                }

                return $certificate;
            });
        } catch (\Throwable $e) {
            // 保険: DB は ROLLBACK で戻るが Storage は戻らない。書き込み済みの PDF を消して
            // 「DB に行が無いのに氏名入り PDF だけ残る」orphan ファイルを防ぐ。
            // 手本: app/UseCases/SectionImage/StoreAction.php:47-50（同じ型の後始末）
            //
            // ⚠️ 手本と違い、例外は変換せずそのまま投げ直す。この Action は業務例外（409 の
            //    CertificateAlreadyIssuedException など）も投げるため、一律に 500 へ変換すると
            //    「二重発行の拒否」が Handler の 409 リダイレクト経路に乗らなくなる。
            if ($writtenPdfPath !== null) {
                Storage::disk('private')->delete($writtenPdfPath);
            }

            throw $e;
        }
    }
}
