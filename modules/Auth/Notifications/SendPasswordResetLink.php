<?php

declare(strict_types=1);

namespace Modules\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SendPasswordResetLink extends Notification implements ShouldQueue
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

    public function toMail(object $notifiable): MailMessage
    {
        // Build reset URL with query parameters
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $resetUrl = rtrim($frontendUrl, '/') . '/reset-password?email=' . urlencode($notifiable->email) . '&token=' . urlencode($this->token);

        return (new MailMessage())
            ->subject('Reset Your Password')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $resetUrl)
            ->line('This password reset link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}
