<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invitation;
use App\Notifications\RetriesWithBackoff;
use App\Services\InvitationTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 招待メール。
 *
 * ⭐ ShouldQueue(中身の無い目印のインターフェース)を付けると、呼び出し側が `Mail::send()` のままでも
 * Laravel がキューへ積むほうに切り替える(Illuminate/Mail/Mailer.php:353-357 の `instanceof ShouldQueue`)。
 * 実際の送信は worker(`sail artisan queue:work`)が行う(T-A-05)。
 *
 * ⚠️ commit 前に積まないよう、呼び出し側で `DB::afterCommit()` に包むこと(decisions #270)。
 * 手本: IssueInvitationAction の末尾。
 */
class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    // 失敗したら待ってやり直す(最大 4 回・10 秒 → 60 秒 → 300 秒。通知と同じ決まり。decisions #271)
    use RetriesWithBackoff;

    public function __construct(public Invitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->invitation->email],
            subject: 'Certify LMS への招待',
        );
    }

    public function content(): Content
    {
        $url = app(InvitationTokenService::class)->generateUrl($this->invitation);

        return new Content(
            markdown: 'emails.invitation',
            with: [
                'invitation' => $this->invitation,
                'invitedBy' => $this->invitation->invitedBy,
                'roleLabel' => $this->invitation->role->label(),
                'expiresAt' => $this->invitation->expires_at,
                'url' => $url,
            ],
        );
    }
}
