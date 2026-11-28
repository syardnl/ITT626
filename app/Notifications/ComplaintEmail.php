<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email for one of the four emailed events (design §11). Queued, so a mail failure never
 * touches the Complaint change. It carries only the reference and the short message, never the
 * description, photos or anyone's contact details, and links to the authenticated detail page.
 */
class ComplaintEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly NotificationEvent $event,
        public readonly int $complaintId,
        public readonly string $reference,
        public readonly string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->event->emailSubject($this->reference))
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message)
            ->action('Open the Complaint', rtrim(config('app.url'), '/')."{$notifiable->spaSection()}/complaints/{$this->complaintId}")
            ->line('Sign in to see the details.');
    }
}
