<?php

namespace App\Notifications;

use App\Models\EpicComment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Somebody added to the conversation on an epic you have commented on.
 *
 * Recipients are the epic's other participants -- commenting is how you
 * opt in, since epics have no owner or watcher list.
 */
class EpicCommented extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** A comment deleted before the queue drains is nothing to announce. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public EpicComment $comment)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $epic = $this->comment->epic;
        $verb = $this->comment->parent_id ? 'replied to a thread on' : 'commented on';

        return (new MailMessage)
            ->subject("New comment on \"{$epic->title}\"")
            ->line("{$this->comment->user->name} {$verb} \"{$epic->title}\":")
            ->line('"'.Str::limit($this->comment->body, 300).'"')
            ->action('View epic', url("/epics/{$epic->id}/edit"))
            ->line('You are receiving this because you commented on this epic.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'epic_commented',
            'epic_id' => $this->comment->epic_id,
            'epic_title' => $this->comment->epic->title,
            'actor' => $this->comment->user->name,
            'excerpt' => Str::limit($this->comment->body, 120),
            'is_reply' => $this->comment->parent_id !== null,
        ];
    }
}
