<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;

/**
 * 面談詳細画面に必要なリレーションを揃える Action。
 *
 * 認可(当事者 or admin のみ閲覧可)は MeetingPolicy::view() が担うため、ここでは扱わない。
 * Controller 側の $this->authorize('view', $meeting) を通過した Meeting だけが渡ってくる前提。
 *
 * `load()` ではなく `loadMissing()` を使うのは支給コード以来の挙動を保つため——
 * 既に読み込み済みのリレーションを二度問い合わせない(Route Model Binding の後に
 * 他所で eager load されているケースを壊さない)。
 */
final class ShowAction
{
    public function __invoke(Meeting $meeting): Meeting
    {
        // loadMissing() は読み込んだ後の自分自身($this)を返すため、そのまま return できる
        return $meeting->loadMissing([
            'enrollment.certification',
            'coach',
            'student',
            'canceledBy',
            'meetingMemo',
        ]);
    }
}
