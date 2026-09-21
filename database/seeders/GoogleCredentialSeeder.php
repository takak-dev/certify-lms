<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GoogleCredential;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Database\Seeder;

/**
 * Google カレンダー連携のデモデータシーダー(S-A-01)。
 *
 * 原典 初期データ「連携済のコーチと未連携のコーチを両方用意する
 * (予約画面での空き枠反映・連携状態の表示・連携解除の動作を確認できる状態にする)」。
 *
 * **設計思想**:
 *
 * - **連携済 = coach2**: 面談設定タブが「連携中」バッジ + 連携日時 + 解除ボタンを出す状態になる。
 * - **未連携 = coach（coach@certify-lms.test）**: 同じ画面が「未連携」バッジ + 連携ボタンを出す状態になる。
 *   固定コーチ 2 人で両方の見た目を並べられるので、スクリーンショットが 1 往復で撮れる。
 *
 * ⚠️ **投入するトークンはダミーで、Google では通用しない。**
 *    連携状態の表示と解除の動作確認はこれで足りるが、予定の自動登録・空き枠への反映を
 *    本当に確かめるには、画面から実際に Google アカウントを連携し直す必要がある
 *    (要件シート「利用には各自でのキー取得・.env 設定が必要」)。
 *
 * ⚠️ ダミーのまま連携済コーチの予約画面を開くと、空き枠計算が Google を呼んで失敗し、
 *    warning ログが 1 行出る。これは異常ではなくフォールバックが働いている証拠で、
 *    枠は従来どおり表示される(原典 共通の振る舞い)。
 *    気になる場合は面談設定タブから「連携を解除する」を押せば呼ばれなくなる。
 *
 * 依存: UserSeeder の後に走る前提(固定コーチのアカウントを参照する)。
 */
final class GoogleCredentialSeeder extends Seeder
{
    public function run(): void
    {
        $linkedCoach = User::query()->where('email', 'coach2@certify-lms.test')->first();

        if ($linkedCoach === null) {
            return;
        }

        // ⚠️ 期限は未来にしておく。過去にすると画面を開くたびにトークン更新の通信が走り、
        //    ダミーなので必ず失敗する。連携状態の表示を確かめたいだけの場面で待たされる。
        GoogleCredential::factory()->forUser($linkedCoach)->create([
            'calendar_id' => GoogleCalendarService::PRIMARY_CALENDAR_ID,
            'connected_at' => now()->subDays(7),
        ]);

        // coach@certify-lms.test は**意図的に未連携のまま**にする。
        // 「連携していないコーチは従来どおりの空き判定で動く」(decisions #146)を
        // 画面で確認できるようにするため。行を作らないことがそのままデータになる。
    }
}
