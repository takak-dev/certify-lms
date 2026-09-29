<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Jobs\DeliverAnnouncementJob;
use App\Mail\InvitationMail;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\DeliversToActiveUsersOnly;
use App\Notifications\QaReplyReceivedNotification;
use App\Notifications\RetriesWithBackoff;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * 配信対象の制御（decisions #76）が新しい通知クラスで抜け落ちないよう、コードで強制する Architecture テスト。
 *
 * ⭐ このテストがある理由。
 * Laravel は通知クラスに `shouldSend()` が「あれば呼ぶ」という緩い契約になっている
 * (vendor/laravel/framework/src/Illuminate/Notifications/NotificationSender.php:163-168)。
 * インターフェースによる強制が無いため、判定を書き忘れた通知クラスは
 * **修了者や管理者にも通知が飛ぶ**。しかも例外は出ず、静かに配信される。
 *
 * S-B-08（運営お知らせ）と S-B-09（面談リマインダー）で通知クラスが 2 つ増えることが確定しているため、
 * 付け忘れが起きる前提の構造になっている。ここで機械的に落とす。
 *
 * ⚠️ 検査するのは「`shouldSend()` があるか」ではなく「トレイトを use しているか」。
 * メソッドの有無だけを見ると、①中身が `return true;` の空実装 ②`protected` で宣言（呼び出し時に致命エラー）
 * のどちらも素通りする。判定の実体を 1 箇所に集約し、それを使っているかを見る形にした。
 * 自前の判定を書きたい通知が出てきたら、EXEMPT に理由つきで足す（意図の表明を強制する）。
 *
 * ⭐ T-A-05 で「キューで送っているか」の検査も同居させた（decisions #271）。対象は通知・メール・ジョブ（#274）。
 * ShouldQueue も「付いていなければ同期で送る」だけで、付け忘れても例外が出ない同じ形の緩さを持つ。
 * 付け忘れると一斉配信で管理画面が待たされ、失敗してもやり直さない状態に静かに戻る。
 */
class NotificationDeliveryArchitectureTest extends TestCase
{
    /**
     * 配信対象の制御を課さない通知クラス。
     *
     * `ResetPasswordNotification` は認証系で、招待中（invited）や修了後のユーザーにも
     * パスワード再設定メールが届く必要がある。業務イベント通知とは配信条件が違うため対象外。
     *
     * @var array<int, class-string>
     */
    private const EXEMPT = [
        ResetPasswordNotification::class,
    ];

    public function test_every_business_notification_controls_its_audience(): void
    {
        // Arrange
        $violations = [];

        foreach ($this->notificationClasses() as $class) {
            if (in_array($class, self::EXEMPT, true)) {
                continue;
            }

            // Act: 判定の実体（トレイト）を使っているか。class_uses_recursive は親クラス経由も辿る
            if (! in_array(DeliversToActiveUsersOnly::class, class_uses_recursive($class), true)) {
                $violations[] = $class;
            }
        }

        // Assert
        $this->assertEmpty(
            $violations,
            "通知クラスは配信対象の制御を持つ必要があります（decisions #76）。\n".
            "`use DeliversToActiveUsersOnly;` を足すか、配信条件が違うなら本テストの EXEMPT に理由つきで追加してください。\n".
            "書き忘れると判定が呼ばれず、修了者・管理者にも通知が飛びます（例外は出ません）:\n".
            implode("\n", $violations),
        );
    }

    /**
     * キューで送らない通知クラス（decisions #265）。
     *
     * `ResetPasswordNotification` はキューに積むと再設定トークンが平文で jobs に保存され、
     * 失敗時は failed_jobs にも残り続ける。1 通ずつの送信で一斉配信の遅延問題も無いため同期のまま。
     *
     * @var array<int, class-string>
     */
    private const QUEUE_EXEMPT = [
        ResetPasswordNotification::class,
    ];

    public function test_every_notification_mail_and_job_is_queued_with_retry(): void
    {
        // Arrange: 通知（app/Notifications）・メール（app/Mail）・ジョブ（app/Jobs）を集める。
        //          原典 T-A-05 の対象は「通知 / メール送信」なので、将来メールが増えても同じ決まりを課す。
        //          ジョブは送信を担う「配る係」（DeliverAnnouncementJob。decisions #274）が付け忘れると、
        //          worker の既定（1 回で諦める）が効いて一斉配信がまるごと失敗ジョブ行きになるので同じ決まりを課す
        $classes = array_merge(
            $this->notificationClasses(),
            $this->classesUnder('app/Mail', 'App\\Mail\\', Mailable::class),
            $this->classesUnder('app/Jobs', 'App\\Jobs\\', ShouldQueue::class),
        );
        $violations = [];

        foreach ($classes as $class) {
            if (in_array($class, self::QUEUE_EXEMPT, true)) {
                continue;
            }

            // Act: 名札（ShouldQueue）と、やり直しの決まり（RetriesWithBackoff）の両方を持つか。
            //      名札だけだと失敗しても 1 回で諦め、部品だけだとそもそもキューに積まれない
            $queued = is_subclass_of($class, ShouldQueue::class);
            $retries = in_array(RetriesWithBackoff::class, class_uses_recursive($class), true);

            if (! $queued || ! $retries) {
                $violations[] = $class.'（ShouldQueue: '.($queued ? 'あり' : 'なし').' / RetriesWithBackoff: '.($retries ? 'あり' : 'なし').'）';
            }
        }

        // Assert
        $this->assertEmpty(
            $violations,
            "通知・メール・ジョブはキューで送り、失敗したらやり直す必要があります（T-A-05 / decisions #271）。\n".
            "`implements ShouldQueue` と `use RetriesWithBackoff;` を足すか（通知は `use Queueable;` も必要）、\n".
            "同期で送る理由があるなら本テストの QUEUE_EXEMPT に理由つきで追加してください:\n".
            implode("\n", $violations),
        );
    }

    public function test_queue_exempt_classes_are_really_sent_synchronously(): void
    {
        // Arrange: 上の検査は QUEUE_EXEMPT のクラスを「飛ばす」だけなので、除外したクラスに後から
        //          ShouldQueue を付けても誰も気づかない。除外リストを「同期で送ることの保証」にするため、
        //          逆向きに検査する(#265 の理由: 再設定トークンを jobs / failed_jobs に平文で残さない)
        $violations = [];

        foreach (self::QUEUE_EXEMPT as $class) {
            // Act: 名札(ShouldQueue)が付いていたら違反。親クラス経由で付いた場合も is_subclass_of が拾う
            if (is_subclass_of($class, ShouldQueue::class)) {
                $violations[] = $class;
            }
        }

        // Assert
        $this->assertEmpty(
            $violations,
            "QUEUE_EXEMPT のクラスがキューで送る設定になっています（decisions #265）。\n".
            "キューに積むと、通知が持つ値（パスワード再設定トークン等）が jobs / failed_jobs に平文で残ります。\n".
            "キューで送るのが正しいなら、QUEUE_EXEMPT から外して理由を decisions に記帳してください:\n".
            implode("\n", $violations),
        );
    }

    public function test_the_check_actually_finds_notification_classes(): void
    {
        // Arrange & Act: 走査そのものが壊れていないことを確かめる。
        //                パスの書き間違いで 0 件になると、上のテストが常に緑になってしまう
        $classes = $this->notificationClasses();

        // Assert
        $this->assertNotEmpty($classes, 'app/Notifications/ から通知クラスを 1 つも見つけられていません。走査条件を確認してください。');
        $this->assertContains(QaReplyReceivedNotification::class, $classes);
        // サブディレクトリ（Auth/）まで辿れているか。ここを取りこぼすと EXEMPT が意味を失う
        $this->assertContains(ResetPasswordNotification::class, $classes);
    }

    public function test_the_check_actually_finds_job_classes(): void
    {
        // Arrange & Act: app/Jobs の走査が 0 件になると、キューの検査からジョブが静かに漏れる
        $classes = $this->classesUnder('app/Jobs', 'App\\Jobs\\', ShouldQueue::class);

        // Assert
        $this->assertContains(DeliverAnnouncementJob::class, $classes);
    }

    public function test_the_check_actually_finds_mail_classes(): void
    {
        // Arrange & Act: app/Mail の走査が 0 件になると、キューの検査からメールが静かに漏れる
        $classes = $this->classesUnder('app/Mail', 'App\\Mail\\', Mailable::class);

        // Assert
        $this->assertContains(InvitationMail::class, $classes);
    }

    /**
     * app/Notifications/ 配下の、インスタンス化できる通知クラスを集める。
     *
     * @return array<int, class-string>
     */
    private function notificationClasses(): array
    {
        return $this->classesUnder('app/Notifications', 'App\\Notifications\\', Notification::class);
    }

    /**
     * 指定ディレクトリ配下の、$parent を継承したインスタンス化できるクラスを集める。
     * 走査は既存の Architecture テストに倣う（手本: DashboardArchitectureTest::test_dashboard_actions_do_not_use_cache_facade）。
     *
     * @param string $dir base_path() からの相対パス（例: app/Notifications）
     * @param string $namespace $dir に対応する名前空間の接頭辞（例: App\Notifications\）
     * @param class-string $parent 集める対象の親クラス
     *
     * @return array<int, class-string>
     */
    private function classesUnder(string $dir, string $namespace, string $parent): array
    {
        $base = base_path($dir);
        $classes = [];

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));

        foreach ($rii as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // app/Notifications/Foo/Bar.php → App\Notifications\Foo\Bar
            $relative = substr($file->getPathname(), strlen($base) + 1);
            $class = $namespace.str_replace(['/', '.php'], ['\\', ''], $relative);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            // トレイト・抽象クラス・$parent を継承しないものは対象外
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf($parent)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }
}
