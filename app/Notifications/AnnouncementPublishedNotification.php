<?php

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Support\Str;

class AnnouncementPublishedNotification extends EcoNotification
{
    public function __construct(public Announcement $announcement) {}

    public function type(): string
    {
        return 'announcement.published';
    }

    protected function message(object $notifiable): array
    {
        $summary = Str::of(strip_tags((string) $this->announcement->summary))->squish()->limit(140)->toString();

        return [
            'category' => 'announcement',
            'title' => $this->announcement->title,
            'body' => $summary !== '' ? $summary : null,
            'action_url' => "/announcements/{$this->announcement->slug}",
            'meta' => ['announcement_id' => $this->announcement->id],
        ];
    }
}
