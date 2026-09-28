<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 相談の発言 1 件(S-A-02)。受講生の質問と AI の応答が同じテーブルに並ぶ(role で区別)。
 *
 * ⭐ 応答は「先に pending の行を作り、Gemini から返ってきたら completed / error へ更新する」。
 *    原典「AI 応答に失敗しても受講生の質問は残り、同じ内容を送り直して再質問できる」を満たすため、
 *    受講生の発言の保存と Gemini の呼び出しを同じトランザクションに入れない。
 *    途中で処理が落ちても、質問は残り、応答は pending のまま残る。
 *
 * ⚠️ モデル名 / 応答時間 / トークン数の列は持たない(decisions #233)。
 *    支給 Blade resources/views/ai-chat/_partials/message-bubble.blade.php:75,78 と
 *    支給 JS resources/js/ai-chat/message-renderer.js:88-89 が「値が入っていれば表示する」ため、
 *    列を作って埋めた瞬間に原典「運用観測メタデータは受講生には表示しない」を破る。
 *    観測記録は支給済みのログチャネル ai-chat (config/logging.php:132) に出す。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // 会話を削除したら発言も消える(原典 会話の削除)。手本は chat_messages:22。
            $table->foreignUlid('ai_chat_conversation_id')
                ->constrained('ai_chat_conversations')
                ->cascadeOnDelete();

            // AiChatMessageRole(user / assistant)。Enum を string 列で持つのは既存の流儀
            // (payments:47 / meetings.status / meeting_packs.status と同じ)。
            $table->string('role', 20);

            // 本文。長さは支給 Blade の入力欄が 2000 文字(ai-chat/_partials/input-form.blade.php:19)。
            // 応答側に上限は無いので text のままにする。
            $table->text('content');

            // AiChatMessageStatus(pending / completed / error)。
            // 既定を pending にしておくと、応答行を作る側が状態を書き忘れても
            // 「まだ返ってきていない」という安全側に倒れる。
            $table->string('status', 20)->default('pending');

            // 失敗の理由。⭐ ここに Gemini が返した HTTP ステータス番号を含める必要がある
            // ——支給 Blade message-bubble.blade.php:55-56 が str_contains($errorDetail, '429')
            // などで受講生向けの文言を出し分けているため。
            $table->text('error_detail')->nullable();

            // created_at は発言時刻の表示に、updated_at はエラー行の時刻表示に使われる
            // (message-bubble.blade.php:72,74)。
            $table->timestamps();

            // 1 会話分のメッセージを古い順に引く(message-list.blade.php:15)。手本は chat_messages:29。
            $table->index(['ai_chat_conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
    }
};
