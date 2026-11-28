<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Complaint;
use Illuminate\Notifications\Notification;

/**
 * An in-app notification about a Complaint. The message is written for its recipient when it is
 * sent and kept as it was, so a later change to the Complaint never rewrites what someone was told.
 */
class ComplaintNotification extends Notification
{
    public function __construct(
        public readonly NotificationEvent $event,
        public readonly Complaint $complaint,
        public readonly string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->event->value;
    }

    /** @return array{complaint_id: int, reference_no: string, message: string} */
    public function toDatabase(object $notifiable): array
    {
        return ['complaint_id' => $this->complaint->id, 'reference_no' => $this->complaint->reference_no, 'message' => $this->message];
    }
}
