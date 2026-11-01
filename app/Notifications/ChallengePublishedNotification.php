<?php

namespace App\Notifications;

use App\Models\Challenge;

class ChallengePublishedNotification extends EcoNotification
{
    public function __construct(public Challenge $challenge) {}

    public function type(): string
    {
        return 'challenge.published';
    }

    protected function message(object $notifiable): array
    {
        $points = (int) $this->challenge->reward_points;

        return [
            'category' => 'challenge',
            'title' => "Nuevo reto: {$this->challenge->title}",
            'body' => $points > 0 ? "Participa y gana {$points} puntos." : 'Sal a explorar y participa.',
            'action_url' => "/retos/{$this->challenge->id}",
            'meta' => ['challenge_id' => $this->challenge->id, 'points' => $points],
        ];
    }
}
