<?php

namespace App\Notifications;

use App\Models\Epic;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * An epic you have commented on moved to another column.
 *
 * Database only: a column move is worth a glance at the bell, not an
 * email. Status names travel as strings because the statuses themselves
 * can be renamed or deleted before the queue drains.
 */
class EpicStatusChanged extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Epic $epic,
        public ?string $from,
        public ?string $to,
        public string $actor,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'epic_status_changed',
            'epic_id' => $this->epic->id,
            'epic_title' => $this->epic->title,
            'actor' => $this->actor,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
