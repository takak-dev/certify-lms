<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\UseCases\GoogleCalendar\RemoveMeetingEventAction;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Illuminate\Support\Facades\DB;

/**
 * 当事者(受講生 or コーチ)による面談キャンセルを行う Action。
 * reserved かつ開始前のみキャンセル可。消費済の面談回数 1 回分を返却する。
 *
 * 認可(当事者か)は Controller の $this->authorize('cancel', $meeting) が済ませている前提。
 * $actor をここで auth()->user() から取らず引数で受けるのは、Action を HTTP から切り離すため
 * (バッチ・テストから同じように呼べる)。$actor は canceled_by_user_id と通知先の決定に使う。
 *
 * ⚠️ 処理順が仕様。lockForUpdate による読み直し → ガード → 更新 → 返却 を
 *    「同じロック・同じトランザクション」に収める。Route Model Binding が読んだ $meeting は
 *    トランザクション開始前の古い値なので、ガードに使うと二重キャンセル(= 面談回数の二重返却)を
 *    素通りさせる。外部連動(通知 / Google)は afterCommit に置き、ロックを持ったまま外部通信しない。
 *
 * @throws MeetingStatusTransitionException reserved 以外だった(409)
 * @throws MeetingAlreadyStartedException 開始時刻を過ぎていた(409)
 */
final class CancelAction
{
    public function __construct(
        private readonly RefundQuotaAction $refundAction,
        private readonly RemoveMeetingEventAction $removeAction,
    ) {}

    public function __invoke(Meeting $meeting, User $actor): void
    {
        DB::transaction(function () use ($meeting, $actor) {
            // lockForUpdate() = SQL の SELECT ... FOR UPDATE。この行はトランザクションが終わるまで
            // 他のリクエストが読み書きできない。古い $meeting ではなくここで読み直した値でガードする
            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== MeetingStatus::Reserved) {
                throw MeetingStatusTransitionException::forCancel();
            }

            if ($locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw new MeetingAlreadyStartedException;
            }

            $locked->update([
                'status' => MeetingStatus::Canceled->value,
                'canceled_by_user_id' => $actor->id,
                'canceled_at' => now(),
            ]);

            // 消費済の 1 回分を返却する。残数は取引の積み上げ(MeetingQuotaService::remaining)で求めるため、
            // 返却は refunded を 1 行足して表す(max_meetings はプラン付与の総数を持つ列であり、
            // キャンセル返却で触る列ではない)。
            // 返却先はキャンセル操作者ではなく面談の受講生: コーチがキャンセルした場合も回数は受講生に戻る。
            // 上の Reserved ガードと同じロック・同じトランザクション内に置くことで、二重返却と
            // 「status だけ canceled で返却されていない」状態の両方を防ぐ。
            ($this->refundAction)($locked->student, $locked->id);

            // キャンセルした本人ではなく「相手方」へ通知する(S-B-04)。
            // キャンセル確認画面が「相手方に通知メールが届きます」と明記している
            // (meeting/_modals/cancel-confirm.blade.php:21。decisions #77)。
            $counterpart = $actor->id === $locked->student_id
                ? $locked->coach
                : $locked->student;

            DB::afterCommit(function () use ($locked, $counterpart): void {
                $counterpart?->notify(new MeetingCanceledNotification($locked));
            });

            // 登録済なら Google カレンダーからも予定を消す(S-A-01)。
            // StoreAction と同じ理由で afterCommit に置く。未登録の面談(未連携コーチ / 連携前の予約 /
            // 登録に失敗した予約)は Action 側が google_event_id を見て何もしない。
            DB::afterCommit(function () use ($locked): void {
                ($this->removeAction)($locked);
            });
        });
    }
}
