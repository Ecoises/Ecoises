<?php

namespace App\Services;

use App\Models\EducationalContent;
use App\Models\EducationalContentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EducationalContentVersionService
{
    public function capture(
        EducationalContent $content,
        string $event,
        ?string $summary = null,
        ?int $actorId = null,
    ): ?EducationalContentVersion {
        if (! Schema::hasTable('educational_content_versions')) {
            return null;
        }

        $content->loadMissing([
            'categories:id',
            'articleDetails',
            'courseDetails',
            'lessons.activities',
            'assets',
        ]);

        $snapshot = $this->snapshot($content);

        return DB::transaction(function () use ($content, $event, $summary, $actorId, $snapshot): EducationalContentVersion {
            $lastVersion = EducationalContentVersion::query()
                ->where('content_id', $content->id)
                ->lockForUpdate()
                ->max('version_number');

            return EducationalContentVersion::create([
                'content_id' => $content->id,
                'version_number' => ((int) $lastVersion) + 1,
                'created_by' => $actorId ?? auth()->id(),
                'event' => $event,
                'change_summary' => $summary ?? EducationalContentVersion::getEvents()[$event] ?? $event,
                'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'snapshot' => $snapshot,
            ]);
        });
    }

    private function snapshot(EducationalContent $content): array
    {
        return [
            'content' => $content->only([
                'content_type',
                'title',
                'slug',
                'description',
                'thumbnail_url',
                'author_id',
                'tags',
                'difficulty_level',
                'estimated_duration',
                'is_published',
                'is_featured',
                'status',
                'published_at',
            ]),
            'category_ids' => $content->categories->modelKeys(),
            'article_details' => $content->articleDetails?->toArray(),
            'course_details' => $content->courseDetails?->toArray(),
            'lessons' => $content->lessons->map(fn ($lesson): array => [
                ...$lesson->toArray(),
                'activities' => $lesson->activities->map(fn ($activity): array => $activity->getAttributes())->all(),
            ])->all(),
            'assets' => $content->assets->map(fn ($asset): array => $asset->toArray())->all(),
        ];
    }
}
