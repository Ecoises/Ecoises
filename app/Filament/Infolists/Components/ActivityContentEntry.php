<?php

namespace App\Filament\Infolists\Components;

use Filament\Infolists\Components\Entry;

class ActivityContentEntry extends Entry
{
    protected string $view = 'filament.infolists.components.activity-content-entry';

     /**
     * Obtener el tipo de actividad del registro actual
     */
    public function getActivityType(): ?string
    {
        return $this->getRecord()?->activity_type;
    }

    /**
     * Obtener el título/pregunta de la actividad
     */
    public function getActivityTitle(): ?string
    {
        return $this->getRecord()?->title;
    }

    /**
     * Obtener la explicación/feedback de la actividad
     */
    public function getActivityExplanation(): ?string
    {
        return $this->getRecord()?->explanation;
    }
}
