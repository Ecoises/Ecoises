<?php

namespace App\Services;

use App\Models\Announcement;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AnnouncementPublicationService
{
    public function publish(Announcement $announcement): Announcement
    {
        $errors = $this->validationErrors($announcement);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $announcement->forceFill([
            'status' => Announcement::STATUS_PUBLISHED,
            'published_at' => now(),
        ])->save();

        return $announcement->refresh();
    }

    public function unpublish(Announcement $announcement): Announcement
    {
        $announcement->forceFill([
            'status' => Announcement::STATUS_DRAFT,
            'published_at' => null,
        ])->save();

        return $announcement->refresh();
    }

    /** @return array<string, array<int, string>> */
    public function validationErrors(Announcement $announcement): array
    {
        $errors = [];

        if (blank($announcement->title) || Str::startsWith($announcement->title, 'Borrador sin título')) {
            $errors['title'][] = 'Escribe un título definitivo.';
        }

        if (blank(strip_tags((string) $announcement->summary)) && blank(strip_tags((string) $announcement->body))) {
            $errors['body'][] = 'Añade un resumen o contenido antes de publicar.';
        }

        if ($announcement->starts_at && $announcement->ends_at && $announcement->ends_at->lessThanOrEqualTo($announcement->starts_at)) {
            $errors['ends_at'][] = 'La fecha de finalización debe ser posterior al inicio.';
        }

        if (filled($announcement->cta_label) && blank($announcement->cta_url)) {
            $errors['cta_url'][] = 'El botón necesita una dirección de destino.';
        }

        return $errors;
    }
}
