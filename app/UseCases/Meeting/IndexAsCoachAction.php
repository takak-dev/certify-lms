<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * コーチ宛の面談一覧を取得する Action。担当受講生 / 受講登録での絞り込みを併せて提供する。
 *
 * ⚠️ 受講生向けの IndexAction とは **並び順が違う**ので 1 つにまとめない。
 * コーチの upcoming だけ昇順で、「次にやる面談」を先頭に置く(1 日に複数件を持つため)。
 * past / all は直近の活動を先頭にしたいので降順。並び順が分岐ごとに違うので
 * orderBy は match の外に出せない。
 */
final class IndexAsCoachAction
{
    public function __invoke(
        User $coach,
        string $filter,
        ?string $studentId,
        ?string $enrollmentId,
    ): LengthAwarePaginator {
        $query = Meeting::query()
            ->with(['enrollment.certification', 'student'])
            ->forCoach($coach)
            // when() は第 1 引数が「真」のときだけクロージャを実行する Eloquent のヘルパ。
            // null のときは何もしないので、if 文を書かずに絞り込みを足せる
            ->when($studentId, fn ($q, $id) => $q->where('student_id', $id))
            ->when($enrollmentId, fn ($q, $id) => $q->where('enrollment_id', $id));

        // upcoming: 次の面談を一番上に置く (昇順) / past + all: 直近の活動を一番上 (降順)
        return match ($filter) {
            'past' => $query->past()->orderByDesc('scheduled_at')->paginate(20),
            'all' => $query->orderByDesc('scheduled_at')->paginate(20),
            default => $query->upcoming()->orderBy('scheduled_at')->paginate(20),
        };
    }
}
