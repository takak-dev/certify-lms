<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 修了証 PDF のダウンロード Controller(S-A-04)。
 *
 * 認可は CertificatePolicy::download(本人 / 担当コーチ / admin)で完結する。
 * ⚠️ ルートに `active-learning` を付けない——修了(graduated)した受講生も自分の修了証を取得できる必要があり、
 *    修了者ダッシュボード(resources/views/dashboard/graduated.blade.php:52)はこのボタンしか持たない。
 */
class CertificateController extends Controller
{
    public function download(Certificate $certificate): StreamedResponse
    {
        $this->authorize('download', $certificate);

        $disk = Storage::disk('private');

        // PDF の実体が無ければダウンロードさせない(原典「PDF ファイルが保管領域に見つからない場合は
        // ダウンロードできない」)。S-A-04 より前に発行された行は pdf_path だけ持ち実体が無い。
        abort_unless($disk->exists($certificate->pdf_path), 404);

        $certificate->loadMissing('certification');

        // ファイル名に使えない文字を落とす。資格名は管理者が自由入力できる(実データに「販売終了: 〜」がある)。
        //
        // 必須なのは `/` と `\` の 2 つだけ——Symfony の HeaderUtils::makeDisposition(:186) がこの 2 文字を
        // 含むファイル名で InvalidArgumentException を投げる。`: * ? " < > |` は例外にはならないが、
        // Windows がファイル名に使えないため受け取った側で保存に失敗する。どちらも落としておく。
        // preg_replace は不正な UTF-8 で null を返すので `??` で退避する。
        $certificationName = preg_replace('#[/\\\\:*?"<>|]#u', '-', $certificate->certification->name)
            ?? $certificate->certification_id;

        // download() = Content-Disposition: attachment。原典「ダウンロードはファイル添付形式で配信する」。
        // 個人情報なので共有端末のブラウザキャッシュに残さない(no-store)。
        return $disk->download(
            $certificate->pdf_path,
            "修了証_{$certificationName}.pdf",
            ['Cache-Control' => 'private, no-store, max-age=0'],
        );
    }
}
