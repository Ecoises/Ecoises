<?php

namespace App\Notifications;

use App\Models\Observation;

/** Un moderador aprobó o rechazó un avistamiento enviado como evidencia de un reto. */
class ChallengeEvidenceReviewedNotification extends EcoNotification
{
    public function __construct(public Observation $observation) {}

    public function type(): string
    {
        return $this->approved() ? 'challenge.evidence_approved' : 'challenge.evidence_rejected';
    }

    protected function message(object $notifiable): array
    {
        $challenge = $this->observation->challenge;
        $title = $challenge?->title ?? 'tu reto';
        $note = trim((string) $this->observation->review_note);

        return [
            'category' => 'challenge',
            'title' => $this->approved() ? '¡Evidencia aprobada!' : 'Tu evidencia no fue aceptada',
            'body' => $this->approved()
                ? "Tu avistamiento ya cuenta para el reto «{$title}»."
                : "Revisamos tu avistamiento para «{$title}».".($note !== '' ? " Motivo: {$note}" : ''),
            'action_url' => $challenge ? "/retos/{$challenge->id}" : "/observations/{$this->observation->id}",
            'meta' => [
                'challenge_id' => $challenge?->id,
                'observation_id' => $this->observation->id,
            ],
        ];
    }

    private function approved(): bool
    {
        return $this->observation->verification_status === Observation::STATUS_APPROVED;
    }
}
