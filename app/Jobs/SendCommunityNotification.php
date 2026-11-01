<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Models\Challenge;
use App\Notifications\AnnouncementPublishedNotification;
use App\Notifications\ChallengePublishedNotification;
use App\Services\Notifications\UserNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa a toda la comunidad de un anuncio o reto nuevo.
 *
 * Si todavía no empieza su vigencia, se vuelve a programar para esa fecha;
 * si al ejecutarse ya no está visible (se despublicó), no envía nada.
 */
class SendCommunityNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    /** Margen para cuando la fecha de inicio se cambia varias veces. */
    public int $tries = 5;

    public function __construct(public Announcement|Challenge $subject) {}

    public static function schedule(Announcement|Challenge $subject): void
    {
        $startsAt = $subject instanceof Announcement ? $subject->starts_at : $subject->start_date;

        static::dispatch($subject)
            ->delay($startsAt?->isFuture() ? $startsAt : null)
            ->afterCommit();
    }

    public function handle(UserNotifier $notifier): void
    {
        $subject = $this->subject->fresh();

        if (! $subject) {
            return;
        }

        $startsAt = $subject instanceof Announcement ? $subject->starts_at : $subject->start_date;

        // Le movieron la fecha de inicio después de programarlo: se espera de nuevo.
        // (Con la cola "sync" release() no reprograma, así que no hay bucle.)
        if ($startsAt?->isFuture()) {
            $this->release((int) ceil(now()->diffInSeconds($startsAt)));

            return;
        }

        if ($subject instanceof Announcement) {
            if (Announcement::visible()->whereKey($subject->id)->exists()) {
                $notifier->sendToCommunity(new AnnouncementPublishedNotification($subject), 'announcement_id', $subject->id);
            }

            return;
        }

        if ($subject->isAvailable()) {
            $notifier->sendToCommunity(new ChallengePublishedNotification($subject), 'challenge_id', $subject->id);
        }
    }
}
