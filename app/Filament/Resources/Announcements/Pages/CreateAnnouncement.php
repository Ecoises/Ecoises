<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Models\Announcement;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    public function mount(): void
    {
        $this->authorizeAccess();

        $announcement = Announcement::create([
            'title' => 'Borrador sin título',
            'slug' => 'anuncio-borrador-'.Str::lower(Str::random(16)),
            'author_id' => auth()->id(),
            'status' => Announcement::STATUS_DRAFT,
            'audience' => 'all',
        ]);

        $this->redirect(
            static::getResource()::getUrl('edit', ['record' => $announcement]),
            navigate: true,
        );
    }
}
