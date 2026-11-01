<?php

namespace App\Notifications;

use App\Models\Achievement;

class AchievementUnlockedNotification extends EcoNotification
{
    public function __construct(public Achievement $achievement) {}

    public function type(): string
    {
        return 'progress.achievement';
    }

    protected function message(object $notifiable): array
    {
        $points = (int) $this->achievement->points;

        return [
            'category' => 'progress',
            'title' => "Logro desbloqueado: {$this->achievement->name}",
            'body' => $this->achievement->description ?: ($points > 0 ? "Ganaste {$points} puntos." : null),
            'action_url' => '/profile',
            'image' => $this->achievement->icon_url,
            'meta' => ['achievement_id' => $this->achievement->id, 'points' => $points],
        ];
    }
}
