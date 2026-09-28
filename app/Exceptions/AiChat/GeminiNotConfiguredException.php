<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Gemini の API キーが設定されていない環境で AI 相談を使おうとしたときの例外(S-A-02・HTTP 503)。
 *
 * 原典の非機能要件「AI の API キーが未設定の環境では、利用できない旨を案内する」に対応する。
 *
 * ⚠️ GeminiRequestFailedException(AI が失敗した)とは扱いを分ける。
 *    あちらは「送った質問は残し、応答をエラーとして保存する」道だが、こちらは**環境の不備**で、
 *    何度送り直しても直らない。会話にエラーの行を積んでも受講生の役に立たないため、
 *    呼び出し側は行を作らずに案内だけを返す。
 *
 * ⚠️ 502 にしてはいけない(decisions #239)。支給 JS resources/js/ai-chat/floating-widget.js:157-165 は
 *    502 を `type:'llm'` として扱い「AI が応答できませんでした。**再度お試しください**」と出す。
 *    何度試しても直らない状況でその案内を出すと、原典の「利用できない旨を案内する」に反する。
 */
final class GeminiNotConfiguredException extends ServiceUnavailableHttpException
{
    public function __construct()
    {
        parent::__construct(null, 'AI 相談は現在ご利用いただけません。管理者にお問い合わせください。');
    }
}
