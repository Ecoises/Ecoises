<?php

namespace App\Services;

use App\Filament\Resources\EducationalContents\EducationalContentResource;
use App\Models\EducationalContent;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Schema;

class EducationalWorkflowNotificationService
{
    public function submittedForReview(EducationalContent $content): void
    {
        if (! $this->canSendRoleNotifications()) {
            return;
        }

        $editors = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['editor', 'super_admin']))
            ->where('is_active', true)
            ->get();

        if ($editors->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('Contenido pendiente de revisión')
            ->body("{$content->author?->full_name} envió “{$content->title}” al flujo editorial.")
            ->icon('heroicon-o-document-magnifying-glass')
            ->color('warning')
            ->actions([
                Action::make('review')
                    ->label('Revisar contenido')
                    ->url(EducationalContentResource::getUrl('edit', ['record' => $content])),
            ])
            ->sendToDatabase($editors);
    }

    public function reviewed(EducationalContent $content): void
    {
        $this->notifyAuthor(
            $content,
            'Tu contenido fue aprobado',
            "“{$content->title}” superó la revisión editorial y está listo para publicación.",
            'success',
        );
    }

    public function returnedToDraft(EducationalContent $content): void
    {
        $this->notifyAuthor(
            $content,
            'Tu contenido necesita ajustes',
            "“{$content->title}” volvió a borrador para que puedas realizar correcciones.",
            'warning',
        );
    }

    public function published(EducationalContent $content): void
    {
        $this->notifyAuthor(
            $content,
            'Tu contenido ya está publicado',
            "“{$content->title}” ya está disponible para la comunidad de Ecoises.",
            'success',
        );
    }

    public function unpublished(EducationalContent $content): void
    {
        $this->notifyAuthor(
            $content,
            'Contenido retirado de publicación',
            "“{$content->title}” dejó de estar visible y volvió al estado de borrador.",
            'warning',
        );
    }

    private function notifyAuthor(
        EducationalContent $content,
        string $title,
        string $body,
        string $color,
    ): void {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $author = $content->author;

        if (! $author || ! $author->is_active) {
            return;
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->icon('heroicon-o-academic-cap')
            ->color($color)
            ->actions([
                Action::make('view')
                    ->label('Ver contenido')
                    ->url(EducationalContentResource::getUrl('view', ['record' => $content])),
            ])
            ->sendToDatabase($author);
    }

    private function canSendRoleNotifications(): bool
    {
        return Schema::hasTable('notifications')
            && Schema::hasTable('roles')
            && Schema::hasTable('model_has_roles');
    }
}
