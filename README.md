# Certify LMS

マルチ資格対応の資格学習プラットフォームです。受講生は資格ごとの教材で学習し、演習問題・模擬試験で理解度を確かめながら、コーチの面談サポートを受けて資格取得を目指せます。

> プロジェクト構造・ドメインモデル・コードの読み進め方は [ONBOARDING.md](./ONBOARDING.md) を参照してください。

## 主な機能

| ロール | 機能 |
|---|---|
| 受講生（student） | 教材閲覧 / 演習問題・苦手分野ドリル / 模擬試験（分野別ヒートマップ・合格可能性スコア）/ 面談予約 / チャット / 学習時間・進捗・ストリーク管理 / 修了証の受領 |
| コーチ（coach） | 教材・演習問題・模試の管理 / 担当受講生の進捗フォロー / 面談対応・面談メモ / Google カレンダー連携 / チャット |
| 管理者（admin） | ユーザー招待・管理 / 資格・資格分類マスタ管理 / 資格へのコーチ割当 / 面談回数の付与 / 全体ダッシュボード |

## 動作環境

- Docker Desktop / Docker Compose
- 開発環境は Laravel Sail で構築します（PHP コンテナ・MySQL・Mailpit・phpMyAdmin を起動）

## 環境構築手順

### 1. リポジトリの clone

```bash
git clone <このリポジトリの URL>
cd <リポジトリ名>
```

### 2. 環境変数ファイルの作成

```bash
cp .env.example .env
```

`.env.example` は Sail 向けに設定済みのため、コピーするだけでローカル開発を始められます（外部サービス連携のキーは後述）。

### 3. 依存パッケージのインストール（初回のみ）

`vendor/` がまだ無いため、初回のみ Docker 経由で Composer を実行します。

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

### 4. Sail エイリアスの設定（推奨）

```bash
alias sail='./vendor/bin/sail'
```

以降のコマンドはこのエイリアス前提で記載します（未設定の場合は `./vendor/bin/sail` に読み替えてください）。

### 5. コンテナの起動

```bash
sail up -d
```

### 6. アプリケーションの初期化

```bash
sail artisan key:generate
sail artisan storage:link
sail artisan migrate:fresh --seed
```

`storage:link` は教材画像・プロフィール画像の配信に必要です。`migrate:fresh --seed` でテーブル作成とデモデータ投入が行われます（いつでも再実行してデータを初期状態に戻せます）。

### 7. フロントエンドのビルド

```bash
sail npm install
sail npm run build
```

Blade / CSS / JS を編集しながら開発する場合は、`build` の代わりに `sail npm run dev` を起動したままにしてください（Vite のホットリロードが効きます）。

### 8. 動作確認

http://localhost:8000 にアクセスし、下記の[ログインアカウント](#ログインアカウント)でログインできればセットアップ完了です。

## 開発環境 URL

| 用途 | URL |
|---|---|
| アプリケーション | http://localhost:8000 |
| phpMyAdmin（DB 確認） | http://localhost:8080 |
| Mailpit（メール確認） | http://localhost:8025 |

アプリケーションが送信するメール（招待メールなど）はすべて Mailpit に届きます。実際のメールは送信されません。

## ログインアカウント

`migrate:fresh --seed` 後、以下の固定アカウントが使えます（パスワードはすべて `password`）。

| ロール | メールアドレス | 備考 |
|---|---|---|
| 管理者 | admin@certify-lms.test | 全機能にアクセス可能 |
| コーチ | coach@certify-lms.test | IT 系資格の担当 |
| コーチ | coach2@certify-lms.test | ビジネス系資格の担当 |
| 受講生 | student@certify-lms.test | 受講中の資格・学習履歴・面談などのデモデータ付き |

このほか、ライフサイクル（招待中 / 受講中 / 卒業 / 退会）を網羅したデモユーザーが投入されます。

> 本サービスは**招待制**です。公開の会員登録画面はありません。新規ユーザーを作るには、管理者でログイン → ユーザー管理から招待 → Mailpit で招待メールの URL を開く → オンボーディング登録、という流れになります。

## テスト

```bash
sail artisan test                  # 全テスト実行
sail artisan test --filter=Xxx    # クラス名・メソッド名で絞り込み
```

## コード整形

Laravel Pint を使用しています。コミット前に実行してください。

```bash
sail bin pint --dirty    # 変更ファイルのみ整形
sail bin pint --test     # 整形漏れの確認（CI 相当のチェック）
```

## 使用技術

- PHP 8.5 / Laravel 10
- MySQL 8.4
- Laravel Fortify（認証）/ Laravel Sanctum（API 認証）
- Blade + Tailwind CSS + Vite（JavaScript は素の JS、フレームワーク不使用）
- PHPUnit / Laravel Pint
- league/commonmark（教材本文の Markdown レンダリング）
- Pusher（チャットのリアルタイム配信）
- google/apiclient（コーチの Google カレンダー連携）
- Docker（Laravel Sail）

## 環境変数

`.env.example` をコピーするだけで、外部サービス連携を除くすべての機能がローカルで動作します（メールは Mailpit に配信されます）。外部サービスと連携する機能は、下記のキーを各自で取得して設定してください。**未設定でも、その機能以外は通常どおり動作します。**

- `PUSHER_*` — チャットのリアルタイム配信に使用します。有効にする場合は Pusher のキーを取得して設定し、`BROADCAST_DRIVER=pusher` に変更してください。未設定（既定の `BROADCAST_DRIVER=log`）でもメッセージの送受信自体は動作し、相手画面へのリアルタイム反映のみ行われません
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI` — コーチの Google カレンダー連携に使用します。設定手順は下記を参照してください。未設定の場合、面談設定タブの連携ボタンを押すと「設定されていません」と案内され、面談の予約・キャンセル・空き枠表示は従来どおり動作します

### Google カレンダー連携のセットアップ（任意）

コーチが自分の Google アカウントを連携すると、Google カレンダーに予定がある時刻が受講生の予約画面から除外され、面談の成立・キャンセルがカレンダーへ自動反映されます。

1. [Google Cloud コンソール](https://console.cloud.google.com/) でプロジェクトを作成する
2. 「APIとサービス」→「ライブラリ」から **Google Calendar API** を有効にする
3. 「Google Auth Platform」→ **Branding** でアプリ名とサポートメールを設定する
4. **Audience** で User type を「外部」にし、公開ステータスは「テスト」のまま **テストユーザー**に連携したい Google アカウントを追加する
5. **Clients** →「クライアントを作成」→ 種類は **ウェブ アプリケーション**。**承認済みのリダイレクト URI** に次を追加する

   ```
   http://localhost:8000/settings/google-calendar/callback
   ```

   ※ ポートは `.env` の `APP_PORT`（既定は `8000`）に合わせてください。Google Cloud 側に登録した URI と `GOOGLE_REDIRECT_URI` は**完全に一致**している必要があります（末尾スラッシュの有無も区別されます）
6. 発行されたクライアント ID とシークレットを `.env` に設定する

   ```dotenv
   GOOGLE_CLIENT_ID=取得したクライアントID
   GOOGLE_CLIENT_SECRET=取得したクライアントシークレット
   GOOGLE_REDIRECT_URI=http://localhost:8000/settings/google-calendar/callback
   ```

7. コーチでログインし、**設定 → 面談設定**タブの「Googleカレンダーと連携する」から連携する

要求するスコープは `calendar.events`（予定の作成・削除）と `calendar.freebusy`（予定の有無の取得）の 2 つだけです。カレンダー自体の共有設定や削除ができる広い権限（`calendar`）は要求しません。

> なお `calendar.events` には予定を読み取る権限も含まれますが、**本アプリは予定の内容を読み取る処理を実装していません**（取得するのは空き状況のみ）。

> ⚠️ **本番運用では認証情報の暗号化を推奨します。**
> 現在の実装は `google_credentials` テーブルにアクセストークンとリフレッシュトークンを**平文で保存**しています。本番環境では Laravel の `encrypted` キャストなどで暗号化し、データベースのバックアップや閲覧権限の管理とあわせて保護してください。

新しい環境変数やセットアップ手順を追加した場合は、`.env.example` と本 README に追記し、チームの誰でも環境を再現できる状態を保ってください。
