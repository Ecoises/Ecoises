<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\EcoNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Punto único para enviar notificaciones de la app.
 *
 * Un fallo al notificar nunca debe romper la acción que lo originó
 * (aprobar una evidencia, otorgar puntos...), por eso se registra y se sigue.
 */
class UserNotifier
{
    public function send(?User $user, EcoNotification $notification): void
    {
        $inactive = $user?->is_active !== null && ! $user->is_active;

        if (! $user || $inactive || ! $this->ready()) {
            return;
        }

        try {
            // Se encola después del commit para no avisar de algo que luego se revierte.
            $user->notify($notification->afterCommit());
        } catch (Throwable $error) {
            Log::warning('No se pudo enviar la notificación', [
                'type' => $notification->type(),
                'user_id' => $user->id,
                'message' => $error->getMessage(),
            ]);
        }
    }

    /**
     * Envía la notificación a todos los usuarios activos que aún no la tengan
     * para el mismo recurso (por ejemplo, el mismo anuncio publicado dos veces).
     *
     * @return int Usuarios notificados.
     */
    public function sendToCommunity(EcoNotification $notification, string $metaKey, int $subjectId): int
    {
        if (! $this->ready()) {
            return 0;
        }

        $type = EcoNotification::TYPE_PREFIX.$notification->type();
        $sent = 0;

        User::query()
            ->where('is_active', true)
            ->whereDoesntHave('notifications', fn ($query) => $query
                ->where('type', $type)
                ->where("data->meta->{$metaKey}", $subjectId))
            ->select('id')
            ->chunkById(200, function ($users) use ($notification, &$sent): void {
                Notification::sendNow($users, $notification);
                $sent += $users->count();
            });

        return $sent;
    }

    private function ready(): bool
    {
        return Schema::hasTable('notifications');
    }
}
