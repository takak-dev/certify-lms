<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use App\Services\AnnouncementRecipientService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 開発用 お知らせ配信シーダー。
 *
 * **チケット S-B-08「初期データ」の指定に対応する**:
 *
 * 1. **配信対象の 3 種類それぞれのお知らせを投入する**: 履歴一覧のバッジ(全受講生 / 資格指定 /
 *    ユーザー指定)と、詳細画面の対象表示を確認できるようにする。
 *
 * 2. **各お知らせに紐づく受講生の通知も投入する**: 受講生側で通知一覧・未読バッジ・
 *    通知詳細ページを確認できるようにする。
 *
 * ⚠️ 通知クラスの `notify()` は呼ばない。理由は NotificationSeeder と同じ——
 * ①メールが実際に送られ、シーダーを流すたび Mailpit が埋まる
 * ②`created_at` が全部 now() になり、新着順を確認できない
 * 代わりに `toDatabase()` を呼んで中身だけ借りる。本番の通知とデータの形がずれない。
 *
 * ⭐ 配信対象は `AnnouncementRecipientService` を通す。実際の配信(StoreAction)と同じ 1 つの定義を使うため。
 * ここで条件を書き写すと、「履歴の配信件数と、実際に通知を受け取った人数が合わない」初期データができる。
 *
 * 依存順序: `UserSeeder` → `CertificationSeeder` → `EnrollmentSeeder` → 本 Seeder。
 * 受講登録が無いと「資格指定」の配信対象が 0 件になる。
 */
final class AnnouncementSeeder extends Seeder
{
    /** 固定の受講生。ユーザー指定の配信先にして、ログイン直後に受信を確認できるようにする */
    private const STUDENT_EMAIL = 'student@certify-lms.test';

    /** 配信した管理者 */
    private const ADMIN_EMAIL = 'admin@certify-lms.test';

    public function run(): void
    {
        $admin = User::query()->where('email', self::ADMIN_EMAIL)->first();
        $student = User::query()->where('email', self::STUDENT_EMAIL)->first();

        if ($admin === null || $student === null) {
            $this->command?->warn('AnnouncementSeeder: 固定ユーザーが揃っていません。先に UserSeeder を実行してください。');

            return;
        }

        // 「資格指定」は固定の受講生が受講登録している資格を選ぶ。
        // 無関係な資格を選ぶと配信件数が 0 になり、受講生側の受信を画面で確認できない
        $certification = $student->enrollments()->first()?->certification
            ?? Certification::query()->orderBy('name')->first();

        if ($certification === null) {
            $this->command?->warn('AnnouncementSeeder: 資格がありません。先に CertificationSeeder を実行してください。');

            return;
        }

        $now = Carbon::now();

        // 一覧が配信時刻の降順に並ぶことを確認できるよう、配信時刻をずらす。
        // 配列の並び = 古い順。あとで一覧を開くと逆順(ユーザー指定が先頭)になる
        $plans = [
            [
                'type' => AnnouncementTargetType::AllStudents,
                'title' => '年末年始の運営休止についてのお知らせ',
                'body' => "12 月 29 日から 1 月 3 日まで、運営窓口を休止します。\n\n"
                    ."期間中も教材の閲覧・演習・模試はご利用いただけます。面談の予約は 1 月 4 日以降の枠が対象です。\n\n"
                    .'ご不便をおかけしますが、よろしくお願いいたします。',
                'dispatched_at' => $now->copy()->subDays(9),
                'target_certification_id' => null,
                'target_user_id' => null,
            ],
            [
                'type' => AnnouncementTargetType::Certification,
                'title' => $certification->name.' の教材を改訂しました',
                'body' => "試験範囲の改訂にともない、対象の教材を更新しました。\n\n"
                    ."更新箇所は各章の冒頭に記載しています。学習中の方は該当章をあらためてご確認ください。\n\n"
                    .'すでに学習済みの章についても、差分のみを確認できるようにしています。',
                'dispatched_at' => $now->copy()->subDays(4),
                'target_certification_id' => $certification->id,
                'target_user_id' => null,
            ],
            [
                'type' => AnnouncementTargetType::User,
                'title' => '学習の進み方についてのご連絡',
                'body' => "いつも学習お疲れさまです。運営です。\n\n"
                    ."直近 2 週間の学習時間が目標を下回っています。無理のない範囲で計画を見直してみてください。\n\n"
                    .'面談で相談したい場合は、予約枠からお申し込みいただけます。',
                'dispatched_at' => $now->copy()->subDay(),
                'target_certification_id' => null,
                'target_user_id' => $student->id,
            ],
        ];

        $recipientService = app(AnnouncementRecipientService::class);

        foreach ($plans as $plan) {
            $announcement = new Announcement([
                'title' => $plan['title'],
                'body' => $plan['body'],
                'target_type' => $plan['type']->value,
                'target_certification_id' => $plan['target_certification_id'],
                'target_user_id' => $plan['target_user_id'],
            ]);

            $recipients = $recipientService->resolve($announcement);

            $announcement->created_by_user_id = $admin->id;
            $announcement->dispatched_at = $plan['dispatched_at'];
            $announcement->dispatched_count = $recipients->count();
            // 配信時刻と作成時刻を揃える。一覧の並び(dispatched_at 降順)と履歴の見え方が一致する
            $announcement->created_at = $plan['dispatched_at'];
            $announcement->updated_at = $plan['dispatched_at'];
            $announcement->save();

            $this->seedNotifications($announcement, $recipients);
        }
    }

    /**
     * 配信対象それぞれに、受講生側の通知行を作る。
     *
     * @param Collection<int, User> $recipients
     */
    private function seedNotifications(Announcement $announcement, Collection $recipients): void
    {
        $notification = new AdminAnnouncementNotification($announcement);

        foreach ($recipients as $recipient) {
            $recipient->notifications()->create([
                // 主キーは Laravel の採番に合わせて UUID(decisions #78)
                'id' => (string) Str::uuid(),
                'type' => $notification::class,
                // 本番と同じ組み立てを通す。キー名がずれる余地を作らない
                'data' => $notification->toDatabase($recipient),
                // お知らせは全部未読にする。運営からの連絡が届いていることを未読バッジで示したい
                'read_at' => null,
                'created_at' => $announcement->dispatched_at,
                'updated_at' => $announcement->dispatched_at,
            ]);
        }
    }
}
