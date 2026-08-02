<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class FranchiseAssignmentApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly array $assignment,
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
        return (new MailMessage())
            ->subject('Franchise assignment approved')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your franchise assignment has been approved.')
            ->line(
                'Branch: ' .
                ($this->assignment['branch_name'] ?? 'Assigned branch')
            )
            ->line(
                'Coverage location: ' .
                ($this->assignment['coverage_location_name'] ?? 'Assigned coverage')
            )
            ->action(
                'View Branch Assignment',
                config('app.frontend_url') .
                    '/merchant/branch-assignment'
            );
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'franchise_assignment_approved',
            'title' => 'Franchise assignment approved',
            'message' => sprintf(
                'Your assignment to %s has been approved.',
                $this->assignment['branch_name'] ?? 'the selected branch'
            ),
            'branch_id' => $this->assignment['branch_id'] ?? null,
            'coverage_location_id' =>
                $this->assignment['coverage_location_id'] ?? null,
            'url' => '/merchant/branch-assignment',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage(
            $this->toDatabase($notifiable)
        );
    }
}