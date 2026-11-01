<?php

namespace App\Notifications;

use App\Models\Level;

class LevelUpNotification extends EcoNotification
{
    public function __construct(public Level $level) {}

    public function type(): string
    {
        return 'progress.level_up';
    }

    protected function message(object $notifiable): array
    {
        return [
            'category' => 'progress',
            'title' => "¡Subiste a nivel {$this->level->name}!",
            'body' => $this->level->description ?: 'Sigue observando y aprendiendo para llegar más lejos.',
            'action_url' => '/profile',
            'meta' => ['level_id' => $this->level->id],
        ];
    }
}
