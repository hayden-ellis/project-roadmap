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
 * Somebody named you in a comment.
 *
 * Stronger than being a participant: this one reaches people who have never
 * touched the epic. A mentioned participant gets this and not EpicCommented,
 * so nobody hears about one comment twice.
 */
class EpicMentioned extends Notification implements ShouldQueue
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

        return (new MailMessage)
            ->subject("{$this->comment->user->name} mentioned you on \"{$epic->title}\"")
            ->line("{$this->comment->user->name} mentioned you in a comment on \"{$epic->title}\":")
            ->line('"'.Str::limit($this->comment->body, 300).'"')
            ->action('View epic', url("/epics/{$epic->id}/edit"))
            ->line('You are receiving this because you were mentioned by name.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'epic_mentioned',
            'epic_id' => $this->comment->epic_id,
            'epic_title' => $this->comment->epic->title,
            'actor' => $this->comment->user->name,
            'excerpt' => Str::limit($this->comment->body, 120),
        ];
    }
}
