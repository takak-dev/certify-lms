<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\DeliversToActiveUsersOnly;
use App\Notifications\QaReplyReceivedNotification;
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

    /**
     * app/Notifications/ 配下の、インスタンス化できる通知クラスを集める。
     * 走査は既存の Architecture テストに倣う（手本: DashboardArchitectureTest::test_dashboard_actions_do_not_use_cache_facade）。
     *
     * @return array<int, class-string>
     */
    private function notificationClasses(): array
    {
        $base = base_path('app/Notifications');
        $classes = [];

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));

        foreach ($rii as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // app/Notifications/Foo/Bar.php → App\Notifications\Foo\Bar
            $relative = substr($file->getPathname(), strlen($base) + 1);
            $class = 'App\\Notifications\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            // トレイト・抽象クラス・Notification を継承しないものは対象外
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Notification::class)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }
}
