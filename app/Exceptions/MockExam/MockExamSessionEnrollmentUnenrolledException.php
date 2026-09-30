<?php

declare(strict_types=1);

namespace App\Exceptions\MockExam;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 受講解除(受講登録の論理削除)した資格の受験セッションを、開始・解答・提出しようとした際の例外(HTTP 409)。
 *
 * 受講解除の処理(Enrollment\DestroyAction)が未開始・受験中の受験をキャンセル済みにするので、通常はここまで来ない。
 * 解除より前から残っている受験や、解除と受験の作成が同時に走った場合の守り(decisions #285)。
 * 書き方は MockExamUnavailableException と同じ。
 */
final class MockExamSessionEnrollmentUnenrolledException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('受講解除した資格の模試は受験できません。', $previous);
    }
}
