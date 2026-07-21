<?php

namespace Modules\Branch\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Branch\Models\Branch;

class BranchCreatedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Branch $branch,
        public User $manager,
        public int $generatedUserCount,
        public string $setPasswordUrl,
        public string $loginUrl
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject:
                'Your Tukaatu Express branch is ready'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.branch-created'
        );
    }

    public function attachments(): array
    {
        return [];
    }
}