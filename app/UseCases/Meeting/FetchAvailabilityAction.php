<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Enrollment;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * 予約画面が呼ぶ「指定日の空き枠」を取得する Action。
 *
 * 空き枠の計算そのものは MeetingAvailabilityService が持つ(コーチの稼働時間・既存予約・
 * 連携済コーチの Google カレンダーを突き合わせる。S-A-01)。ここはその呼び出しと、
 * 計算に必要な Certification の読み込みだけを受け持つ。
 *
 * ⚠️ 返すのは Carbon を含んだままの Collection。ISO8601 文字列への変換は
 * 「JSON という表現の都合」なので Controller 側のレスポンス整形に残す。
 *
 * @return Collection<int, array{slot_start: Carbon, slot_end: Carbon, available_coach_count: int}>
 */
final class FetchAvailabilityAction
{
    public function __construct(private readonly MeetingAvailabilityService $availability) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function __invoke(Enrollment $enrollment, Carbon $date): Collection
    {
        return $this->availability->slotsForCertification(
            // loadMissing() は未読込のときだけ問い合わせる。->certification でモデルを取り出す
            $enrollment->loadMissing('certification')->certification,
            $date,
        );
    }
}
