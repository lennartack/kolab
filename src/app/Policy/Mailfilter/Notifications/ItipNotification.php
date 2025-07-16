<?php

namespace App\Policy\Mailfilter\Notifications;

use App\Enums\Queue;
use App\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;

class ItipNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public ItipNotificationParams $params;

    /**
     * Create a new notification instance.
     */
    public function __construct(ItipNotificationParams $params)
    {
        $this->params = $params;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $user): Mailable
    {
        $this->params->user = $user;

        return new ItipNotificationMail($this->params);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(User $user): array
    {
        // If we wanted to use different/custom notification channel e.g. IMAP
        // we have to return the Channel class.
        // https://laravel.com/docs/10.x/notifications#custom-channels

        return ['mail'];
    }

    /**
     * Determine which queues should be used for each notification channel.
     *
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return [
            'mail' => Queue::Mail->value,
        ];
    }
}
