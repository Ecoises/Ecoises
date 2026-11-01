<?php

namespace App\Notifications;

use App\Models\Report;

/** Avisa a quien envió un reporte u opinión que el equipo ya lo atendió. */
class ReportClosedNotification extends EcoNotification
{
    public function __construct(public Report $report) {}

    public function type(): string
    {
        return 'moderation.report_closed';
    }

    protected function message(object $notifiable): array
    {
        $isFeedback = in_array($this->report->type, [Report::TYPE_CONTENT_FEEDBACK, Report::TYPE_GENERAL_FEEDBACK], true);
        $resolved = $this->report->status === Report::STATUS_RESOLVED;

        return [
            'category' => 'moderation',
            'title' => $isFeedback ? 'Revisamos tu opinión' : 'Revisamos tu reporte',
            'body' => match (true) {
                $isFeedback => 'Gracias por ayudarnos a mejorar EcoIses.',
                $resolved => 'Tomamos medidas. Gracias por cuidar la comunidad.',
                default => 'Lo revisamos y no encontramos una falta. Gracias por avisarnos.',
            },
            'action_url' => $this->report->observation_id ? "/observations/{$this->report->observation_id}" : null,
            'meta' => ['report_id' => $this->report->id],
        ];
    }
}
