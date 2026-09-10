<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MeetingStatus;
use App\Models\ChatMessage;
use App\Models\Meeting;
use App\Models\QaReply;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use App\Notifications\QaReplyReceivedNotification;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 開発用 通知シーダー。
 *
 * **チケット S-B-04「初期データ」の指定に対応する**:
 *
 * 1. **固定の受講生・コーチに投入する**: ログイン直後に通知一覧と未読バッジを確認できるようにする。
 *    対象は `student@certify-lms.test` と `coach@certify-lms.test`。
 *
 * 2. **既読・未読を混在させる**: 未読だけだと既読行の見た目(グレーのアイコン・細字)を確認できず、
 *    既読だけだと未読バッジと「全件既読にする」ボタンが出ない。
 *
 * 3. **1 ページ(20 件)を超える件数を入れる**: ページネーションの動作確認用。
 *
 * 4. **種別を混在させる**: アイコンの出し分け(`notification-row.blade.php:14-21`)と、
 *    行クリック時の遷移先が種別ごとに違うことを確認するため。
 *
 * ⚠️ 通知クラスの `notify()` は呼ばない。理由は 2 つ——
 * ①メールが実際に送られ、シーダーを流すたび Mailpit が数十通で埋まる
 * ②`created_at` が全部 now() になり、新着順とページネーションを確認できない
 * 代わりに各通知クラスの `toDatabase()` を呼んで中身だけ借りる。こうすれば本番の通知と
 * データの形がずれない(キーを手書きすると画面が拾えないキー名になっても気づけない)。
 *
 * 📌 固定の受講生には面談通知が入らない。予約通知の宛先はコーチだけで(decisions #77)、
 *    キャンセル通知に使える素材（コーチがキャンセルした固定受講生の面談）を
 *    `MentoringSeeder` が作っていないため。欠落ではなく、宛先ルールと既存データ構成の帰結。
 *    4 種類すべては固定の受講生とコーチを合わせれば揃う。
 *
 * 依存順序: `UserSeeder` → `MentoringSeeder`(面談) → `ChatSeeder` → `QaBoardSeeder` → 本 Seeder。
 */
final class NotificationSeeder extends Seeder
{
    /** 固定ユーザー 1 人あたりの投入件数(1 ページ 20 件を超えさせる) */
    private const COUNT_PER_USER = 25;

    /** 先頭から何件を未読にするか(残りは既読) */
    private const UNREAD_COUNT = 6;

    /** 1 種別あたり何件の素材(回答 / メッセージ / 面談)を使うか。
     *  1 件だけだと 25 件が同じ文言の繰り返しになり、一覧が本番の見え方と違ってしまう */
    private const SAMPLES_PER_TYPE = 4;

    public function run(): void
    {
        $recipients = User::query()
            ->whereIn('email', ['student@certify-lms.test', 'coach@certify-lms.test'])
            ->get();

        if ($recipients->count() < 2) {
            $this->command?->warn('NotificationSeeder: 固定ユーザーが揃っていません。先に UserSeeder を実行してください。');

            return;
        }

        foreach ($recipients as $recipient) {
            // 素材は受け手ごとに集める。受け手が当事者でないデータを使うと、
            // 通知をクリックした先で Policy に弾かれて 403 になる
            $groups = $this->buildSampleGroups($recipient);

            if ($groups === []) {
                $this->command?->warn("NotificationSeeder: {$recipient->email} 宛の素材が見つかりません。先に各 Seeder を実行してください。");

                continue;
            }

            $this->seedFor($recipient, $groups);
        }
    }

    /**
     * 受け手が当事者である素材から、通知インスタンスを種別ごとに束ねて返す。
     *
     * ⚠️ 素材は必ず「その受け手が**実際に受け取りうる**」ものに限る。条件は 2 つ。
     * ① 遷移先を開ける（当事者である）こと。他人のデータを指すと、クリックした先で 403 になる
     * ② 自分の操作で発火した通知でないこと。予約通知はコーチだけ、キャンセル通知は相手方だけに飛ぶ
     *    （decisions #77）。ここを外すと「自分が予約した面談の予約通知が自分に届く」という、
     *    本番では起こりえないデータが並び、証跡を見た人に決定が守られていないと誤解される。
     * 先頭から機械的に拾うと他人の面談・他人のチャットを指す通知ができてしまい、
     * 行をクリックした先で MeetingPolicy / ChatRoomPolicy に弾かれて 403 になる。
     * 画面で確認するためのデータなのに画面が確認できなくなるため、ここは受け手で絞る。
     *
     * 種別ごとに複数の素材を使うのは、1 件だけだと本文も遷移先も全通知で同じになり、
     * 一覧が本番の見え方と食い違うため。素材が無い種別は黙って飛ばす
     * (コーチは自分でスレッドを立てないので qa_reply_received の素材が 0 件になる。これは本番と同じ)。
     *
     * @return array<int, array<int, Notification>> 外側が種別、内側が同一種別の複数パターン
     */
    private function buildSampleGroups(User $recipient): array
    {
        $groups = [];

        // 受け手が立てたスレッドへの回答（本番の宛先もスレッド投稿者。decisions #79）
        $replies = QaReply::with('qaThread')
            ->whereHas('qaThread', fn ($q) => $q->where('user_id', $recipient->id))
            // 自分で書いた回答では自分に通知しない（QaReply/StoreAction.php:39 と揃える）
            ->where('user_id', '!=', $recipient->id)
            ->limit(self::SAMPLES_PER_TYPE)
            ->get();
        if ($replies->isNotEmpty()) {
            $groups[] = $replies->map(fn (QaReply $r) => new QaReplyReceivedNotification($r))->all();
        }

        // 受け手が参加しているルームの、他人が送ったメッセージ
        $messages = ChatMessage::with('sender')
            ->whereHas('chatRoom.members', fn ($q) => $q->where('user_id', $recipient->id))
            ->where('sender_user_id', '!=', $recipient->id)
            ->limit(self::SAMPLES_PER_TYPE)
            ->get();
        if ($messages->isNotEmpty()) {
            $groups[] = $messages->map(fn (ChatMessage $m) => new ChatMessageReceivedNotification($m))->all();
        }

        // 予約通知の宛先は担当コーチだけ(受講生本人には送らない。decisions #77)。
        // 受け手がコーチである面談に限る
        $reserved = $this->meetingsFor(
            $recipient,
            MeetingStatus::Reserved,
            fn ($query) => $query->where('coach_id', $recipient->id),
        );
        if ($reserved->isNotEmpty()) {
            $groups[] = $reserved->map(fn (Meeting $m) => new MeetingReservedNotification($m))->all();
        }

        // キャンセル通知の宛先は相手方だけ。受け手がキャンセルした本人の面談は除く(decisions #77)
        $canceled = $this->meetingsFor(
            $recipient,
            MeetingStatus::Canceled,
            fn ($query) => $query->where('canceled_by_user_id', '!=', $recipient->id),
        );
        if ($canceled->isNotEmpty()) {
            $groups[] = $canceled->map(fn (Meeting $m) => new MeetingCanceledNotification($m))->all();
        }

        return $groups;
    }

    /**
     * 受け手が当事者である面談を、状態と宛先ルールで絞って取る。
     *
     * ⚠️ 宛先ルールは必ずクエリ側で受け取る。取得後に Collection で絞ると、
     * 条件に合う面談が LIMIT の外にあったときに素材が黙って 0 件になり、
     * 種別の混在が崩れたことに気づけない。
     *
     * @param Closure(Builder): mixed $filter 宛先ルールによる絞り込み
     *
     * @return Collection<int, Meeting>
     */
    private function meetingsFor(User $recipient, MeetingStatus $status, Closure $filter): Collection
    {
        return Meeting::with(['student', 'coach', 'canceledBy'])
            ->where('status', $status->value)
            ->where(fn ($q) => $q->where('student_id', $recipient->id)->orWhere('coach_id', $recipient->id))
            ->where($filter)
            ->limit(self::SAMPLES_PER_TYPE)
            ->get();
    }

    /**
     * 1 人分の通知を投入する。
     *
     * @param array<int, array<int, Notification>> $groups 種別ごとの通知パターン
     */
    private function seedFor(User $recipient, array $groups): void
    {
        $now = Carbon::now();
        $typeCount = count($groups);

        for ($i = 0; $i < self::COUNT_PER_USER; $i++) {
            // 種別は 1 件ごとに切り替える(0,1,2,3,0,1,… の順)。一覧でアイコンが交互に並ぶ
            $group = $groups[$i % $typeCount];
            // 同じ種別に戻ってきたら、その中の次のパターンを使う。
            // intdiv($i, $typeCount) は「種別を何周したか」なので、周回ごとに素材が変わる
            $notification = $group[intdiv($i, $typeCount) % count($group)];

            $recipient->notifications()->create([
                // 主キーは Laravel の採番に合わせて UUID(migration のコメント参照)
                'id' => (string) Str::uuid(),
                'type' => $notification::class,
                // 本番と同じ組み立てを通す。キー名がずれる余地を作らない
                'data' => $notification->toDatabase($recipient),
                // 先頭の数件だけ未読にして、未読バッジと既読行の両方を確認できるようにする
                'read_at' => $i < self::UNREAD_COUNT ? null : $now->copy()->subHours($i),
                // 1 時間ずつ遡らせる。新着順とページネーションの確認用。
                // created_at を明示的に渡すと Eloquent は now() で上書きしない
                'created_at' => $now->copy()->subHours($i),
                'updated_at' => $now->copy()->subHours($i),
            ]);
        }
    }
}
