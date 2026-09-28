<?php

declare(strict_types=1);

/**
 * AI 相談(S-A-02)の機能設定。
 *
 * ⚠️ 支給 Blade が 2 つのキーを直接読んでいるため、名前を変えると画面が壊れる。
 *    - config('ai-chat.enabled')      … resources/views/layouts/app.blade.php:60
 *    - config('ai-chat.gemini.model') … resources/views/ai-chat/show.blade.php:24
 *                                       resources/views/components/ai-chat/floating-widget.blade.php:14
 *
 * ⚠️ API キーはここに置かない。資格情報は config/services.php の 'gemini' に集約する
 *    (手本: 同ファイルの 'google'(S-A-01) / 'stripe'(S-A-03))。
 */
return [
    /*
     * 機能全体の ON / OFF スイッチ(原典の非機能要件)。
     * false にすると routes/web.php の ai-chat グループごと登録されなくなり、
     * 画面もルートも消える。サイドバーの項目は nav/item.blade.php:14 の Route::has() が
     * 拾って自動的に非表示になるため、Blade 側の対応は不要。
     */
    'enabled' => env('AI_CHAT_ENABLED', true),

    /*
     * 受講生 1 人あたりの 1 日の送信上限(原典の非機能要件)。超過時は HTTP 429 を返す。
     * 暦日(00:00)でリセットする —— 支給 JS の文言が「本日の利用上限に達しました。明日 0:00 以降に
     * 再度ご利用ください。」(resources/js/ai-chat/floating-widget.js:156 /
     * resources/js/ai-chat/full-screen.js:145)。
     */
    'daily_message_limit' => env('AI_CHAT_DAILY_MESSAGE_LIMIT', 5),

    /*
     * Gemini へ引き継ぐ直近のやり取りの件数(user / assistant を合わせた通数)。
     * 原典スコープ外「自前のトークン数切り詰め —— 履歴件数のみで制御」に従い、
     * トークン数ではなく件数だけで頭打ちにする。
     */
    'history_limit' => env('AI_CHAT_HISTORY_LIMIT', 10),

    /*
     * 教材から相談を始めたときに Gemini へ添える Section 本文の先頭文字数(decisions #199)。
     * 全文を送らないのは原典スコープ外「教材本文埋め込みは外部 API 無料枠を圧迫するため見送り」に沿う。
     */
    'section_excerpt_length' => env('AI_CHAT_SECTION_EXCERPT_LENGTH', 1000),

    /*
     * 会話タイトルの自動生成スイッチ(原典 要件「内容に応じて AI が自動で付け直す(無効化スイッチあり)」)。
     *
     * ⭐ 自動生成が走るのは**初回の AI 応答が完了した直後の 1 回だけ** ——
     *    支給 JS resources/js/ai-chat/chat-client.js:72 のコメントが
     *    「タイトルが (初回 assistant 完了直後の) LLM 自動生成で更新されていれば通知」と
     *    サーバ側の挙動を前提に書いている。毎回付け直すと受講生の手動編集
     *    (ai-chat/show.blade.php:143 のタイトル編集モーダル)を上書きしてしまう。
     *
     * ⚠️ 会話ごとのフラグにしないのは、支給画面にトグルが無いため。原典「画面は提供済み
     *    (本チケットでの画面変更は不要)」と両立するのは、環境設定に置く形だけ。
     *
     * false にすると、会話は作成時の仮タイトルのままになる(タイトルは NOT NULL)。
     */
    'auto_title' => env('AI_CHAT_AUTO_TITLE', true),

    'gemini' => [
        // 画面ヘッダのラベルにも使われる(show.blade.php:24)。
        //
        // ⚠️ 支給 Blade のフォールバック値は 'gemini-2.5-flash' だが、そちらには**合わせない**。
        //    新しく発行した API キーでは 404 になるため(2026-09-28 実測。API の返答は
        //    "This model models/gemini-2.5-flash is no longer available to new users.
        //     Please update your code to use models/gemini-3.8-flash")。
        //    当初は支給値に合わせていた(decisions #235)が、動かない既定値を置く理由が無いので
        //    decisions #246 で差し替えた。支給 Blade のフォールバックは、この config が
        //    存在する限り使われない。
        'model' => env('AI_CHAT_GEMINI_MODEL', 'gemini-3.8-flash'),

        // SDK を使わず HTTP で直接叩く(composer.json に Gemini の SDK が無い)。
        'endpoint' => env('AI_CHAT_GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),

        // 同期応答なので、リクエストが返らないと受講生の画面が固まる。短めに倒す。
        'timeout' => env('AI_CHAT_GEMINI_TIMEOUT', 30),
    ],

    /*
     * Gemini に渡すシステム指示(decisions #199 の ①)。
     *
     * 原典スコープ外「システムプロンプトの管理画面 / DB 管理 —— 環境設定で完結」に従い、
     * 管理画面も DB も作らずここに置く。.env で丸ごと差し替えられる。
     *
     * ⚠️ 話題の絞り込みを書いていないのは意図的(decisions #147)。
     *    「学習外の質問には答えずコーチへ誘導する」という当初案は PM に却下されている。
     *    制限するのは試験問題の答えだけ(decisions #148)。
     */
    'system_prompt' => env('AI_CHAT_SYSTEM_PROMPT', <<<'PROMPT'
        あなたは資格取得を目指す受講生を支援する学習アシスタントです。次の方針に従って日本語で回答してください。

        - Markdown 形式で回答し、見出し・箇条書き・コードブロックを使って簡潔にまとめる
        - 学習中の資格や閲覧中の教材が示されている場合は、その文脈を踏まえて回答する
        - 試験問題そのものの答えを求められた場合は、答えを直接示さず、考え方・解法の道筋・着目すべき点を示す
        - 確実でないことは断定せず、不確かである旨を添える
        - 話題は限定しない。資格学習以外の相談にも、分かる範囲で答えてよい
        PROMPT),
];
