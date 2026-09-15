<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Certification\CertificatePdfGenerationException;
use App\Models\Certificate;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * 修了証 PDF を生成する Service。支給テンプレート `resources/views/certificates/pdf.blade.php` を
 * mPDF に渡して PDF のバイナリ文字列を返す。
 *
 * ⭐ 保存はしない。保存先(private disk)への書き込みは呼び出し側の Action がトランザクション内で行う
 *    (手本: app/UseCases/SectionImage/StoreAction.php — Storage 書き込みと DB INSERT を同一トランザクションに置く型)。
 *    「生成」と「保存」を分けているのは、保存先やトランザクションの都合を Service に持ち込まないため
 *    (この Service は Certificate を受け取って PDF のバイナリを返すことだけを知っていればよい)。
 *
 * ⚠️ フォント設定の根拠は decisions #171(実測で確定)。支給テンプレートは font-family を 1 つも持たず、
 *    layouts/pdf.blade.php を継承もしていないため、書体はここでの設定だけで決まる。
 *
 * ⚠️ `final` を付けない。テストで Mockery に差し替えて「PDF 生成だけを失敗させる」ケースを作るため
 *    (app/Services/ の既存 Service も、モックされるものは final を付けていない)。
 */
class CertificatePdfService
{
    /**
     * 修了証 PDF を生成してバイナリ文字列で返す。
     *
     * @throws CertificatePdfGenerationException mPDF がレンダリングに失敗した
     */
    public function render(Certificate $certificate): string
    {
        // Blade が $certificate->user->name / ->certification->name をたどるため先に読み込む。
        // loadMissing は「まだ読み込まれていないリレーションだけ」を読む Eloquent のメソッド
        // (既に eager load 済みなら追加クエリを発行しない)。
        $certificate->loadMissing(['user', 'certification']);

        try {
            // Blade を「画面に出す」のではなく HTML 文字列として受け取る。render() がその変換。
            // try の中に置くのは、Blade 側の失敗も mPDF の失敗と同じ例外に揃えるため
            // (どちらも「PDF を作れなかった」で、呼び出し側の扱いは変わらない)。
            $html = view('certificates.pdf', ['certificate' => $certificate])->render();

            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',

                // 英数字を DejaVu Sans(ゴシック系)で描く。支給の layouts/pdf.blade.php:12 と
                // tailwind.config.js:74,76 がいずれもサンセリフを指しているのに合わせている。
                'default_font' => 'dejavusanscondensed',

                // ⭐ 日本語が出るのはこの 1 行のおかげ。DejaVu に無い文字(漢字・かな)を
                //    mPDF 同梱の Sun-ExtA で自動補完する。これが無いと全て □(豆腐)になる。
                //    補完先の候補は mPDF の既定値に sun-exta が含まれているので指定不要
                //    (vendor/mpdf/mpdf/src/Config/FontVariables.php:28)。
                'useSubstitutions' => true,

                // mPDF の作業ファイルの置き場。既定は vendor/ 配下になってしまうため storage/ に逃がす。
                // ディレクトリが無ければ mPDF が自分で作る(vendor/mpdf/mpdf/src/Cache.php:47)。
                'tempDir' => storage_path('app/mpdf-temp'),
            ]);

            $mpdf->WriteHTML($html);

            // Destination::STRING_RETURN('S') = ファイルに書かずバイナリ文字列で返す。
            return $mpdf->Output('', Destination::STRING_RETURN);
        } catch (\Throwable $e) {
            throw new CertificatePdfGenerationException($e);
        }
    }
}
