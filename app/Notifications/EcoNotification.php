<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Base de las notificaciones que ve el usuario en la app (campana).
 *
 * Todas guardan el mismo esquema en `notifications.data` y un `type` con el
 * prefijo "eco." para distinguirlas de los avisos internos del panel
 * (Filament usa la misma tabla). Se envían con UserNotifier.
 *
 * Además de guardarse, se emiten por Reverb al canal privado
 * App.Models.User.{id}. Cada canal es un job aparte: si el WebSocket falla,
 * la fila ya quedó guardada y la app la recoge con el polling.
 */
abstract class EcoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE_PREFIX = 'eco.';

    /** Identificador estable del evento, por ejemplo "challenge.completed". */
    abstract public function type(): string;

    /**
     * @return array{category: string, title: string, body: ?string, action_url: ?string, image?: ?string, meta?: array<string, mixed>}
     */
    abstract protected function message(object $notifiable): array;

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function databaseType(object $notifiable): string
    {
        return self::TYPE_PREFIX.$this->type();
    }

    public function broadcastType(): string
    {
        return $this->type();
    }

    public function toArray(object $notifiable): array
    {
        return array_merge(['image' => null, 'meta' => []], $this->message($notifiable));
    }

    /**
     * Mismo formato que GET /api/notifications, para que la app lo inserte
     * en su lista tal cual (Laravel añade `id` y `type`).
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage(array_merge($this->toArray($notifiable), [
            'created_at' => now()->toIso8601String(),
        ]));
    }
}
