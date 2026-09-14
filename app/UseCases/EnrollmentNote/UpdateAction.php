<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\EnrollmentNote;
use Illuminate\Support\Facades\DB;

/**
 * 受講生メモの更新ユースケース。本文だけを書き換える。
 *
 * 作成者(author_id)は付け替えない。原典がスコープ外に「コーチ離任時のメモ自動委譲 / 移管 —
 * 自動的な作成者書き換えは行わない」と明記しており、管理者が他人のメモを直しても
 * 作成者は元のコーチのまま残る。$fillable が body だけなので構造的にも書き換わらない。
 *
 * 手本: app/UseCases/EnrollmentGoal/UpdateAction.php。
 */
final class UpdateAction
{
    /**
     * @param array{body: string} $validated EnrollmentNote/UpdateRequest::rules() で検証済
     */
    public function __invoke(EnrollmentNote $note, array $validated): EnrollmentNote
    {
        return DB::transaction(function () use ($note, $validated): EnrollmentNote {
            $note->update($validated);

            // fresh() は DB から読み直した別インスタンスを返す。
            // 呼び出し側が「保存後の確定値」を受け取れるようにする(手本と同じ)
            return $note->fresh();
        });
    }
}
