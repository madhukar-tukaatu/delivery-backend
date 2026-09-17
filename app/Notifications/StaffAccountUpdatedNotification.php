<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class StaffAccountUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly array $changes,
    ) {
    }

    public function via(object $notifiable): array
    {
        return [
            'database',
            'broadcast',
            'mail',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subjectParts = ['Your account has been updated'];

        if (!empty($this->changes['email']) && $this->changes['email']['old'] !== $this->changes['email']['new']) {
            $subjectParts[] = '- Email changed';
        }

        if (!empty($this->changes['password']) && $this->changes['password']['changed']) {
            $subjectParts[] = '- Password changed';
        }

        $lines = [
            'Hello ' . $notifiable->name . ',',
            'Your staff account has been updated by an administrator.',
        ];

        if (!empty($this->changes['email']) && $this->changes['email']['old'] !== $this->changes['email']['new']) {
            $lines[] = sprintf(
                'Your email has been changed from %s to %s.',
                $this->changes['email']['old'],
                $this->changes['email']['new']
            );
        }

        if (!empty($this->changes['password']) && $this->changes['password']['changed']) {
            $lines[] = 'Your password has been updated. Please log in with your new password.';
        }

        if (!empty($this->changes['name'])) {
            $lines[] = sprintf('Your name has been updated to %s.', $this->changes['name']);
        }

        $lines[] = '';
        $lines[] = 'If you did not request these changes, please contact your administrator immediately.';

        return (new MailMessage())
            ->subject(implode(' | ', $subjectParts))
            ->lines($lines);
    }

    public function toDatabase(object $notifiable): array
    {
        $messageParts = ['Your staff account has been updated.'];

        if (!empty($this->changes['email']) && $this->changes['email']['old'] !== $this->changes['email']['new']) {
            $messageParts[] = sprintf('Email changed: %s → %s', $this->changes['email']['old'], $this->changes['email']['new']);
        }

        if (!empty($this->changes['password']) && $this->changes['password']['changed']) {
            $messageParts[] = 'Password changed';
        }

        if (!empty($this->changes['name'])) {
            $messageParts[] = sprintf('Name updated to %s', $this->changes['name']);
        }

        return [
            'type' => 'staff_account_updated',
            'title' => 'Staff Account Updated',
            'message' => implode('. ', $messageParts),
            'url' => '/staff/dashboard',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage(
            $this->toDatabase($notifiable)
        );
    }
}
