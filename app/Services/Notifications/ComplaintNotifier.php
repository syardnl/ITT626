<?php

namespace App\Services\Notifications;

use App\Enums\ComplaintStatus;
use App\Enums\NotificationEvent;
use App\Enums\Role;
use App\Enums\SlaBreachType;
use App\Models\Complaint;
use App\Models\Property;
use App\Models\SlaBreach;
use App\Models\Technician;
use App\Models\User;
use App\Notifications\ComplaintEmail;
use App\Notifications\ComplaintNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Who hears about each Complaint event, and what they are told (design §11). Called inside the
 * workflow's transactions; nothing is sent until the change has committed, so a rolled-back change
 * notifies no one. Nobody is notified about their own action (the system is no one), and inactive
 * accounts are skipped. Four events also send a queued email; Followers never get one.
 */
class ComplaintNotifier
{
    /** A Resident submitted a Complaint: every active Manager. */
    public function submitted(Complaint $complaint, User $reporter): void
    {
        $this->send($this->managers(), $reporter, NotificationEvent::ComplaintSubmitted, $complaint,
            "New Complaint {$complaint->reference_no}: {$complaint->title} at {$complaint->location->name}.");
    }

    /**
     * A Technician was assigned: them; on the first Assignment the reporter too; on a reassignment
     * the Technician it was taken from.
     */
    public function assigned(Complaint $complaint, Technician $technician, User $manager, ?Technician $previous): void
    {
        $this->send([$technician->user], $manager, NotificationEvent::AssignedToYou, $complaint,
            "You have been assigned {$complaint->reference_no}: {$complaint->title} at {$complaint->location->name}.");
        if ($previous === null) {
            $this->send([$complaint->reporter], $manager, NotificationEvent::YourComplaintAssigned, $complaint,
                "Your Complaint {$complaint->reference_no} has been assigned to a Technician.");
        } else {
            $this->send([$previous->user], $manager, NotificationEvent::ReassignedAway, $complaint,
                "{$complaint->reference_no}: {$complaint->title} has been reassigned to another Technician.");
        }
    }

    /** Every status change: the participants it concerns, then the Followers, who see only what the Follower summary shows. */
    public function statusChanged(Complaint $complaint, ComplaintStatus $from, ComplaintStatus $to, ?User $actor): void
    {
        $reference = $complaint->reference_no;
        match (true) {
            $to === ComplaintStatus::PendingVerification => $this->send($this->managers(), $actor, NotificationEvent::WorkPendingVerification, $complaint,
                "Work on {$reference}: {$complaint->title} is ready for you to verify."),
            $to === ComplaintStatus::Returned && $from === ComplaintStatus::Resolved => $this->residentReturned($complaint, $actor),
            $to === ComplaintStatus::Returned => $this->send([$this->currentTechnician($complaint)], $actor, NotificationEvent::WorkReturned, $complaint,
                "Your work on {$reference} was returned by the Manager. Open it to see why."),
            $to === ComplaintStatus::InProgress => $this->send([$complaint->reporter], $actor, NotificationEvent::WorkStarted, $complaint,
                "Work has started on your Complaint {$reference}."),
            $to === ComplaintStatus::Resolved => $this->send([$complaint->reporter], $actor, NotificationEvent::YourComplaintResolved, $complaint,
                "Your Complaint {$reference} has been repaired. Please confirm the repair, or tell us if the problem is still there."),
            $to === ComplaintStatus::Closed => $this->send([$complaint->reporter], $actor, NotificationEvent::YourComplaintClosed, $complaint, $actor === null
                ? "Your Complaint {$reference} was closed automatically after 7 days without an answer."
                : "Your Complaint {$reference} is now Closed."),
            default => null,
        };

        $this->send($complaint->followers()->get(), $actor, NotificationEvent::FollowedComplaintChanged, $complaint,
            "{$reference}: {$complaint->title}, which you follow, is now {$to->label()}.");
    }

    /** An SLA Breach was recorded: every active Manager, once (SlaBreach::notified_at). */
    public function breached(SlaBreach $breach): void
    {
        $complaint = $breach->complaint;
        $target = $breach->type === SlaBreachType::Response ? 'Response' : 'Resolution';
        $due = $breach->due_at->copy()->setTimezone(Property::current()->timezone ?? 'Asia/Kuala_Lumpur')->format('j M Y, g:i A');
        $this->send($this->managers(), null, NotificationEvent::SlaBreached, $complaint,
            "SLA breached: {$complaint->reference_no}: {$complaint->title} missed its {$target} target (due {$due}).");
    }

    private function residentReturned(Complaint $complaint, ?User $resident): void
    {
        $this->send($this->managers(), $resident, NotificationEvent::ResidentReturned, $complaint,
            "The Resident returned {$complaint->reference_no}: {$complaint->title}. The problem is still there.");
        $this->send([$this->currentTechnician($complaint)], $resident, NotificationEvent::WorkReturned, $complaint,
            "{$complaint->reference_no} was returned by the Resident: the problem is still there. Open it to see why.");
    }

    private function currentTechnician(Complaint $complaint): ?User
    {
        return $complaint->currentAssignment()->with('technician.user')->first()?->technician?->user;
    }

    private function managers()
    {
        return User::query()->whereHas('roles', fn ($roles) => $roles->where('name', Role::Manager->value))->get();
    }

    /** @param  iterable<?User>  $recipients */
    private function send(iterable $recipients, ?User $actor, NotificationEvent $event, Complaint $complaint, string $message): void
    {
        $to = collect($recipients)->filter(fn (?User $user) => $user !== null && $user->active && $user->id !== $actor?->id)->unique('id')->values();
        if ($to->isEmpty()) {
            return;
        }

        DB::afterCommit(function () use ($to, $event, $complaint, $message) {
            try {
                foreach ($to as $user) {
                    // A time-ordered id per recipient, so two notifications in the same second still list newest first.
                    $notification = new ComplaintNotification($event, $complaint, $message);
                    $notification->id = (string) Str::orderedUuid();
                    Notification::sendNow($user, $notification);
                }
                if ($event->sendsEmail()) {
                    Notification::send($to, new ComplaintEmail($event, $complaint->id, $complaint->reference_no, $message));   // queued
                }
            } catch (Throwable $failure) {
                report($failure);   // the change is already committed; a lost notification must not fail the request
            }
        });
    }
}
