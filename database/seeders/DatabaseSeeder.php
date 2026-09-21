<?php

declare(strict_types=1);

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            // アバター画像は User が揃ってから。固定アカウント 3 件 + demo 受講生の一部に割り当てる
            AvatarSeeder::class,
            PlanSeeder::class,
            UserLifecycleSeeder::class,
            MeetingPackSeeder::class,
            CertificationCategorySeeder::class,
            CertificationSeeder::class,
            InvitationSeeder::class,
            EnrollmentSeeder::class,
            MentoringSeeder::class,
            // Google カレンダー連携(S-A-01)は固定コーチのアカウントだけを参照するので
            // UserSeeder の後ならどこでもよいが、面談まわりの一連として MentoringSeeder に続けて置く
            GoogleCredentialSeeder::class,
            ContentSeeder::class,
            LearningSeeder::class,
            QuizAnsweringSeeder::class,
            MockExamSeeder::class,
            ChatSeeder::class,
            QaBoardSeeder::class,
            CertificateSeeder::class,
            // 通知は Q&A 回答 / チャット / 面談を素材にするため、それらの後に流す
            NotificationSeeder::class,
            // お知らせは受講登録から配信対象を決めるため EnrollmentSeeder の後。
            // 通知行も自分で作るので NotificationSeeder には依存しない
            AnnouncementSeeder::class,
        ]);
    }
}
