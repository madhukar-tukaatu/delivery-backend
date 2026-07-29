<?php

namespace Modules\Branch\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Branch\Models\Branch;

final class BranchAccountInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Branch $branch,
        public readonly User $manager,
        public readonly string $setPasswordUrl,
        public readonly string $loginUrl
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Tukaatu Express franchise has been approved'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'branch::emails.branch-account-invitation',

            with: [
                'branch' => $this->branch,
                'manager' => $this->manager,
                'setPasswordUrl' => $this->setPasswordUrl,
                'loginUrl' => $this->loginUrl,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}