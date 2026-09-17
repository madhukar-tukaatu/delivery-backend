<?php

declare(strict_types=1);

namespace Modules\Auth\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Auth\Mail\PasswordResetLinkMail;

class SendPasswordResetLink extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
    ) {
    }

    public function via(object $notifiable): array
    {
        return [
            'mail',
        ];
    }

    public function toMail(object $notifiable): PasswordResetLinkMail
    {
        // Build reset URL with query parameters
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $resetUrl = rtrim($frontendUrl, '/') . '/reset-password?email=' . urlencode($notifiable->email) . '&token=' . urlencode($this->token);

        return new PasswordResetLinkMail(
            user: $notifiable,
            resetUrl: $resetUrl
        );
    }
}
