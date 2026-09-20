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
        $changesSummary = $this->buildChangesSummary();

        return (new MailMessage())
            ->subject('Your account has been updated')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your staff account has been updated.')
            ->line($changesSummary)
            ->action(
                'View Your Account',
                config('app.frontend_url') . '/admin/branch-staff'
            );
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'staff_account_updated',
            'title' => 'Account updated',
            'message' => 'Your staff account has been updated with the following changes: ' . $this->buildChangesSummary(),
            'changes' => $this->changes,
            'url' => '/admin/branch-staff',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage(
            $this->toDatabase($notifiable)
        );
    }

    private function buildChangesSummary(): string
    {
        $summary = [];

        if (isset($this->changes['name'])) {
            $summary[] = 'Name updated to: ' . $this->changes['name'];
        }

        if (isset($this->changes['email'])) {
            $summary[] = 'Email updated to: ' . $this->changes['email'];
        }

        if (isset($this->changes['phone'])) {
            $summary[] = 'Phone updated to: ' . $this->changes['phone'];
        }

        if (isset($this->changes['password']) && $this->changes['password']['changed'] === true) {
            $summary[] = 'Password has been changed';
        }

        return implode(', ', $summary);
    }
}
