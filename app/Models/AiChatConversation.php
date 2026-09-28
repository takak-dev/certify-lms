<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 受講生と AI(Gemini)の相談 1 件(S-A-02)。1 行 = 1 つの会話スレッド。
 *
 * 会話の文脈は section / enrollment の有無で決まる(decisions #199)。
 * 支給 Blade resources/views/ai-chat/_partials/context-badges.blade.php:8,11 が
 * この 2 つのリレーションからバッジ(📚 / 🎓 / 全般相談)を毎回計算するため、
 * 「文脈の種類」を列として持たない。
 *
 * 教材から始めた会話は section_id で探して再開する(decisions #201)。同じ教材で新しい会話は作らない。
 *
 * 関連: User(オーナー) / Enrollment(資格文脈) / Section(教材文脈) / AiChatMessage(発言)
 */
class AiChatConversation extends Model
{
    use HasFactory, HasUlids;

    /**
     * ⚠️ user_id は意図的に外してある。生成経路は FindOrCreateConversationAction の
     *    `$user->aiChatConversations()->create([...])` だけで、リレーション側が自動で埋める。
     *    一括代入を許すと、将来 `create($request->validated())` 形に書き換えられたときに
     *    他人名義の会話を作られる経路ができる。
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'enrollment_id',
        'section_id',
        'title',
        'last_message_at',
    ];

    /**
     * 支給 Blade resources/views/ai-chat/show.blade.php:16,18 が
     * `$h->last_message_at?->isToday()` / `->greaterThan(...)` と Carbon のメソッドを呼ぶため、
     * datetime に cast しないと「文字列に対するメソッド呼び出し」で履歴サイドバーが落ちる。
     */
    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 相談の対象になっている受講登録。資格名の供給元
     * (context-badges.blade.php:11-18 が status を見て表示可否を決める)。
     *
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * 教材から始めた相談の Section。教材によらない相談では null。
     *
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * 会話に属する発言。
     *
     * ⚠️ 並び順はここに埋め込まない。既存の流儀どおり、読み出す側(Action)で
     *    `orderBy('created_at')` を指定する(手本: app/UseCases/Chat/ShowAction.php:28)。
     *    支給 Blade resources/views/ai-chat/_partials/message-list.blade.php:15 は
     *    渡されたコレクションをそのまま回すだけなので、**Action が並べ忘れると時系列が崩れる**。
     *
     * @return HasMany<AiChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class);
    }
}
