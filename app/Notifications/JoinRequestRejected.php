<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A zone did not approve the company's request to join an event, and why.
 * The "you are in" answer is AddedToDraw, sent on HQ's first verification.
 * Carries `join_event_id`, like AddedToDraw: the next step is the event page,
 * where the request can be changed and sent again.
 */
class JoinRequestRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Event $event, public string $reason)
    {
        // Sent from inside the office's transaction: only once it holds.
        $this->afterCommit();
    }

    /**
     * In-app only. The app sends no email at all (owner's decision,
     * 2026-11-17). Do not add 'mail' back without that decision.
     */
    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => 'Request not approved',
            'join_event_id' => $this->event->id,
            'approved' => false,
            'event_name' => $this->event->title,
            'message' => 'Your request to join '.$this->event->title." was not approved.\nReason: ".$this->reason,
            'type' => 'alert',
        ];
    }
}
