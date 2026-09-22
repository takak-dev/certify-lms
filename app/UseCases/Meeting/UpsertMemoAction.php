<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use Illuminate\Support\Facades\DB;

/**
 * 担当コーチによる面談メモの作成・更新(upsert)を行う Action。
 *
 * 認可(担当コーチ本人か)は UpsertMemoRequest::authorize() → MeetingPolicy::upsertMemo() が済ませている前提。
 * ここが見るのは「メモを残してよい状態か」だけ —— canceled の面談にはメモを残せない。
 *
 * ⚠️ DB::transaction() が保証するのは「衝突したときに中途半端な状態を残さない」ことだけ。
 *    updateOrCreate() は内部で SELECT(あるか探す) → INSERT or UPDATE の 2 回 SQL を投げるが、
 *    この SELECT は非ロック読み取りなので、**同時実行そのものは防げない**
 *    (config/database.php に isolation_level の指定が無く MySQL 既定の REPEATABLE READ で動くため、
 *     他コネクションの INSERT をブロックしない)。同一面談へ同時に PUT が来れば後着は
 *    meeting_memos.meeting_id の UNIQUE(create_meeting_memos_table.php:21)で 1062 になる。
 *    塞ぐには lockForUpdate() か upsert(ON DUPLICATE KEY) が要るが、**振る舞いを変える変更**なので
 *    T-A-02 のスコープ外。支給時点の形のまま運んでいる(未決リストに起票済み)。
 *
 * @throws MeetingStatusTransitionException reserved / completed 以外の面談だった(409)
 */
final class UpsertMemoAction
{
    public function __invoke(Meeting $meeting, string $body): MeetingMemo
    {
        return DB::transaction(function () use ($meeting, $body) {
            if (! in_array($meeting->status, [MeetingStatus::Reserved, MeetingStatus::Completed], true)) {
                throw MeetingStatusTransitionException::forMemo();
            }

            // 第 1 引数が「探す条件」、第 2 引数が「書き込む値」。1 面談 : 1 メモなので meeting_id で引く
            return MeetingMemo::updateOrCreate(
                ['meeting_id' => $meeting->id],
                ['body' => $body],
            );
        });
    }
}
