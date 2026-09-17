<?php

declare(strict_types=1);

namespace Modules\Auth\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class PasswordResetLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $resetUrl
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset Your Password - Tukaatu Express'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'auth::emails.password-reset-link',
            with: [
                'user' => $this->user,
                'resetUrl' => $this->resetUrl,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
