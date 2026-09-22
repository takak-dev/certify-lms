<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingQuota\MeetingPackNotDeletableException;
use App\Models\MeetingPack;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックを物理削除するユースケース(原典「削除(物理削除...)」)。
 *
 * 公開中は削除できない。画面(show.blade.php:95)も公開中のときは削除ボタンを出さないが、
 * それはブラウザの中だけの制御で、URL を直接叩けば届いてしまう。ここが本当の防御。
 *
 * ⚠️ 購入履歴があるパックも削除不可(decisions #38 / #123)。売った記録を消さないため。
 * 状態ではなく関連データを理由にした拒否だが、MeetingPackPolicy は権限だけを見る設計なので
 * 判定はここに置く(S-B-02 からの方針)。DB 側にも payments の restrictOnDelete があり、
 * ここを通さないと外部キー違反の 500 になる ＝ この判定が「理由の伝わる止め方」を担う。
 *
 * 手本: app/UseCases/CertificationCategory/DestroyAction.php
 */
final class DestroyAction
{
    /**
     * @throws MeetingPackNotDeletableException 公開中、または購入履歴がある
     */
    public function __invoke(MeetingPack $plan): void
    {
        // ⚠️ **取り消せない障害を先に見る**(decisions #127)。状態(公開中)を先に判定すると、
        //    公開中かつ購入履歴ありのパックで「公開中は削除できません」→ アーカイブ →
        //    「購入履歴があるため削除できません」と**管理者が 2 操作の無駄足**を踏み、
        //    その間そのパックは購入導線から外れる。アーカイブは取り消せるが購入履歴は消せない。
        //    手本: app/UseCases/Plan/DestroyAction.php:39-49(users → userPlanLogs → status の順)
        //
        // ⚠️ **状態を問わず** 1 行でも payments があれば拒否する(decisions #232)。
        //    「決済画面で離脱しただけの pending は数えない」ほうが運用は楽だが、
        //    payments.meeting_pack_id は restrictOnDelete なので、アプリが許しても
        //    DB が外部キー違反(500)で止める —— **解釈を DB 制約と一致させる**。
        //    そのパックを画面から消したいだけならアーカイブで足りる。
        //    exists() は件数を数えず「1 行でもあるか」だけを SQL に聞く(count() より軽い)
        if ($plan->payments()->exists()) {
            throw MeetingPackNotDeletableException::forPurchased();
        }

        if ($plan->status === MeetingPackStatus::Published) {
            throw MeetingPackNotDeletableException::forPublished();
        }

        DB::transaction(fn () => $plan->delete());
    }
}
