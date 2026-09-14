<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\EnrollmentNote;
use Illuminate\Support\Facades\DB;

/**
 * 受講生メモの削除ユースケース。
 *
 * ⚠️ 物理削除。EnrollmentNote は SoftDeletes を使っていないので delete() が行を消す。
 *    原典が「削除すると履歴は残らない」と明記し、「メモの復元 UI」をスコープ外に置いている。
 *    誤削除の防止は画面の confirm() が担当する(enrollment-note/_list.blade.php:55)。
 *
 * 状態ログは残さない。**原典が「削除すると履歴は残らない」と明記している**ので、これが直接の根拠。
 * ⚠️ decisions #69(ログを足すかの判断基準)を経由しない。#69 が根拠にしていた
 *    「ログ対象は users と enrollments の 2 つだけ」という CLAUDE.md の記述は不正確で、
 *    実際は user_plan_logs を含めて 3 つある(S-B-07 の監査で判明。#69 に訂正注記あり)。
 *    自前の規約より原典のほうが強い根拠になる。
 *
 * 削除ガードは設けない。メモは他のテーブルから参照されていないため。
 */
final class DestroyAction
{
    public function __invoke(EnrollmentNote $note): void
    {
        DB::transaction(fn () => $note->delete());
    }
}
