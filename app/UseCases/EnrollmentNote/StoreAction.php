<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 受講生メモの追加ユースケース。親の受講登録配下に、書いた本人を作成者として INSERT する。
 *
 * 作成者と親はサーバ側で決める(フォームの値は本文だけを使う)。
 * 手本: app/UseCases/QaReply/StoreAction.php(同じ「親 + 投稿者 + 本文」の形)。
 *
 * ⚠️ EnrollmentGoal/StoreAction のように $enrollment->notes()->create($validated) とは書けない。
 *    あの形はリレーションが外部キーを入れてくれるが、作成者(author_id)は入らないため。
 *    $fillable は body だけなので、外部キー2本は明示的に代入する。
 *
 * 通知は送らない。メモは受講生に見せない業務記録で、知らせる相手がいない
 * (S-B-04 の通知先はいずれも「当事者である受講生 / コーチ」)。
 */
final class StoreAction
{
    /**
     * @param array{body: string} $validated EnrollmentNote/StoreRequest::rules() で検証済
     */
    public function __invoke(User $author, Enrollment $enrollment, array $validated): EnrollmentNote
    {
        return DB::transaction(function () use ($author, $enrollment, $validated): EnrollmentNote {
            $note = new EnrollmentNote($validated);
            $note->enrollment_id = $enrollment->id;
            $note->author_id = $author->id;
            $note->save();

            return $note;
        });
    }
}
