<?php

declare(strict_types=1);

/**
 * 管理者ダッシュボードの集計キャッシュ(T-A-06)の設定。
 *
 * 全体 KPI と資格別修了率は全受講登録を走査する重い集計なので、結果をキャッシュして
 * 連続表示での再計算を避ける。作成と削除は App\Services\EnrollmentStatsService に集約する(decisions #279)。
 *
 * ⚠️ キー名 2 本は支給テストが直接読んでいるため、設定名を変えるとテストが落ちる。
 *    - config('dashboard.admin_kpi_cache_key')             … tests/Feature/UseCases/Dashboard/AdminDashboardCacheTest.php:61
 *    - config('dashboard.admin_completion_rate_cache_key') … tests/Feature/UseCases/Dashboard/AdminDashboardCacheTest.php:100
 */
return [
    /*
     * キャッシュのキー。書式は既存のキーに揃えて「種類:中身」とする
     * (手本: app/Services/MeetingAvailabilityService.php:268 の 'google-busy:...')。
     */
    'admin_kpi_cache_key' => 'admin-dashboard:kpi',
    'admin_completion_rate_cache_key' => 'admin-dashboard:completion-rate',

    /*
     * キャッシュの保存時間(秒)。既定 300 秒(decisions #280)。
     * 受講状態の遷移と受講解除では期限前でも明示的に消すので、この値が効くのは
     * 消す処理を通らない変化(資格名の変更など)が画面に出るまでの遅れだけ。
     * 資格の公開 / 非公開は集計が見ていない(公開状態で絞っていない)ので、数字も遅れも変わらない。
     *
     * .env の値は文字列で届くため (int) で数値にそろえる。
     * 0 以下にすると保存されなくなり、毎回集計する(キャッシュを切りたいときの逃げ道)。
     */
    'admin_cache_ttl_seconds' => (int) env('DASHBOARD_ADMIN_CACHE_TTL_SECONDS', 300),
];
