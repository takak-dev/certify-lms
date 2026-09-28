<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * 通知ポップオーバーの 1 行分の JSON(S-A-05)。
 *
 * 項目はポップオーバーの行テンプレートの空欄に 1 対 1 で対応する
 * (resources/views/notifications/_partials/notification-popover.blade.php:75-81)。
 * 取り出し方と既定値は web 版の一覧行と同じにして、両画面の表示を揃える
 * (resources/views/notifications/_partials/notification-row.blade.php:8-13)。
 *
 * 遷移先の URL は載せない。行クリックで叩く既読化 API が MarkAsReadAction で決めて返すため、
 * 遷移先の判断(外部 URL を弾く / url の無い通知は詳細ページへ)を 1 か所にまとめておける。
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // data は通知クラスの toArray() が返した配列(DB には JSON で入り、cast で配列に戻る)。
        // 壊れた値でも落ちないよう、配列でなければ空として扱う(web 版と同じ)
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'title' => $data['title'] ?? '通知',
            // 通知の種類によって本文のキー名が違う(message / body_preview)
            'message' => $data['message'] ?? ($data['body_preview'] ?? ''),
            // 「3分前」のような画面用の文字。web 版と同じ diffForHumans() でサーバー側で作る
            'created_at_human' => $this->created_at?->diffForHumans() ?? '',
            'is_unread' => $this->read_at === null,
        ];
    }
}
