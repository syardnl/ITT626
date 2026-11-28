<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Enums\Role;
use App\Models\Complaint;
use App\Models\Technician;
use App\Models\User;
use App\Notifications\ComplaintNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Who hears about each Complaint event, and what they are told (design §11). Called inside the
 * workflow's transactions; nothing is sent until the change has committed, so a rolled-back change
 * notifies no one. Nobody is notified about their own action, and inactive accounts are skipped.
 */
class ComplaintNotifier
{
    /** A Resident submitted a Complaint: every active Manager. */
    public function submitted(Complaint $complaint, User $reporter): void
    {
        $this->send(User::query()->whereHas('roles', fn ($roles) => $roles->where('name', Role::Manager->value))->get(), $reporter,
            NotificationEvent::ComplaintSubmitted, $complaint,
            "New Complaint {$complaint->reference_no}: {$complaint->title} at {$complaint->location->name}.");
    }

    /**
     * A Technician was assigned: the Technician, and on the first Assignment the reporter (a
     * reassignment is the office's business; the reporter hears of the work again when it starts).
     */
    public function assigned(Complaint $complaint, Technician $technician, User $manager, bool $first): void
    {
        $this->send([$technician->user], $manager, NotificationEvent::AssignedToYou, $complaint,
            "You have been assigned {$complaint->reference_no}: {$complaint->title} at {$complaint->location->name}.");
        if ($first) {
            $this->send([$complaint->reporter], $manager, NotificationEvent::YourComplaintAssigned, $complaint,
                "Your Complaint {$complaint->reference_no} has been assigned to a Technician.");
        }
    }

    /** @param  iterable<User>  $recipients */
    private function send(iterable $recipients, ?User $actor, NotificationEvent $event, Complaint $complaint, string $message): void
    {
        $to = collect($recipients)->filter(fn (?User $user) => $user !== null && $user->active && $user->id !== $actor?->id)->values();
        if ($to->isEmpty()) {
            return;
        }

        DB::afterCommit(function () use ($to, $event, $complaint, $message) {
            try {
                Notification::sendNow($to, new ComplaintNotification($event, $complaint, $message));
            } catch (Throwable $failure) {
                report($failure);   // the change is already committed; a lost notification must not fail the request
            }
        });
    }
}
