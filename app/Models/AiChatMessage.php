<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI 相談の発言 1 件(S-A-02)。受講生の質問と AI の応答が role で区別されて並ぶ。
 *
 * 応答は Pending の行として先に作り、Gemini から返ってきたら Completed / Error へ更新する。
 * 受講生の質問の保存と Gemini の呼び出しを同じトランザクションに入れないため、AI が落ちても質問は残る
 * (原典「AI 応答に失敗しても受講生の質問は残り、同じ内容を送り直して再質問できる」)。
 *
 * ⚠️ モデル名 / 応答時間 / トークン数は持たない(decisions #233)。観測記録はログチャネル ai-chat へ。
 *
 * 関連: AiChatConversation(親)
 */
class AiChatMessage extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'ai_chat_conversation_id',
        'role',
        'content',
        'status',
        'error_detail',
    ];

    /**
     * 新しいインスタンスの初期値。
     *
     * ⚠️ migration の `->default('pending')` だけでは足りない。DB の既定値は INSERT のときに
     *    効くもので、**PHP 側のインスタンスには反映されない** —— status を渡さずに create() すると、
     *    同じリクエストの中では `$message->status` が null のままになる(実測で判明)。
     *    その状態で支給 Blade message-bubble.blade.php:17 の `$message->status->value` に届くと
     *    「null に対するプロパティ参照」で画面が落ちる。
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => AiChatMessageStatus::Pending->value,
    ];

    /**
     * 支給 Blade resources/views/ai-chat/_partials/message-bubble.blade.php:8 が
     * `$message->role === AiChatMessageRole::User` と**オブジェクト同士で比較**し(:9,:10 は status を同様に比較)、
     * :17 が `$message->status->value` を DOM 属性に焼くため、Enum への cast が必須。
     * cast が無いと文字列と Enum の比較になり、すべての発言が「AI の発言」として描画される。
     */
    protected $casts = [
        'role' => AiChatMessageRole::class,
        'status' => AiChatMessageStatus::class,
    ];

    /**
     * 発言が増えたら親の last_message_at を更新する(非正規化)。
     *
     * 履歴サイドバーが `orderByDesc('last_message_at')` で並べ替え、
     * 「今日 / 過去 7 日 / 過去 30 日」に振り分ける(resources/views/ai-chat/show.blade.php:12,16,18)。
     * 毎回メッセージ側を集計すると会話 30 件ぶんの副問い合わせになるため、親に写しておく。
     *
     * 手本は app/Models/ChatMessage.php:32-38(ChatRoom.last_message_at の更新)。同じ問題への同じ解。
     */
    protected static function booted(): void
    {
        static::created(function (AiChatMessage $message): void {
            AiChatConversation::query()
                ->where('id', $message->ai_chat_conversation_id)
                ->update(['last_message_at' => $message->created_at]);
        });
    }

    /**
     * @return BelongsTo<AiChatConversation, $this>
     */
    public function aiChatConversation(): BelongsTo
    {
        return $this->belongsTo(AiChatConversation::class);
    }
}
