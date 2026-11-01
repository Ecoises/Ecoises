<?php

namespace App\Notifications;

use App\Models\Challenge;

class ChallengeCompletedNotification extends EcoNotification
{
    public function __construct(public Challenge $challenge) {}

    public function type(): string
    {
        return 'challenge.completed';
    }

    protected function message(object $notifiable): array
    {
        $points = (int) $this->challenge->reward_points;

        return [
            'category' => 'challenge',
            'title' => '¡Completaste un reto!',
            'body' => $points > 0
                ? "Terminaste «{$this->challenge->title}» y ganaste {$points} puntos."
                : "Terminaste «{$this->challenge->title}».",
            'action_url' => "/retos/{$this->challenge->id}",
            'meta' => ['challenge_id' => $this->challenge->id, 'points' => $points],
        ];
    }
}
