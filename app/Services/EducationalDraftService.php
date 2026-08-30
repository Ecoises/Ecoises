<?php

namespace App\Services;

use App\Models\EducationalContent;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EducationalDraftService
{
    /**
     * Crea el registro persistente antes de mostrar el editor. De esta forma,
     * adjuntos, lecciones y trabajos de audio siempre tienen un padre real.
     */
    public function create(User $author): EducationalContent
    {
        return DB::transaction(function () use ($author): EducationalContent {
            $draft = EducationalContent::create([
                'content_type' => EducationalContent::TYPE_COURSE,
                'title' => 'Borrador sin título',
                'slug' => 'borrador-'.Str::lower(Str::random(16)),
                'author_id' => $author->id,
                'difficulty_level' => 'principiante',
                'estimated_duration' => 0,
                'status' => EducationalContent::STATUS_DRAFT,
                'is_published' => false,
                'is_featured' => false,
            ]);

            $draft->courseDetails()->create([
                'completion_points' => 100,
            ]);

            // La primera lección también existe desde el comienzo. Esto permite
            // generar su audio sin guardar, salir y volver a abrir el contenido.
            $draft->lessons()->create([
                'title' => 'Lección sin título',
                'slug' => 'leccion-'.Str::lower(Str::random(16)),
                'lesson_order' => 1,
                'content_text' => null,
                'estimated_duration' => 0,
                'is_mandatory' => true,
                'is_published' => false,
                'status' => EducationalContent::STATUS_DRAFT,
                'points' => 10,
            ]);

            return $draft;
        });
    }

    /**
     * Guarda únicamente datos seguros del borrador. Los controles editoriales
     * de publicación se reservan para el botón Guardar y nunca los activa el
     * temporizador de autoguardado.
     */
    public function autosave(EducationalContent $content, array $state): EducationalContent
    {
        return DB::transaction(function () use ($content, $state): EducationalContent {
            $root = Arr::only($state, [
                'content_type',
                'title',
                'slug',
                'description',
                'thumbnail_url',
                'tags',
                'difficulty_level',
                'estimated_duration',
                'is_featured',
            ]);

            if (blank($root['title'] ?? null)) {
                unset($root['title']);
            }

            if (blank($root['slug'] ?? null)
                || EducationalContent::query()
                    ->where('slug', $root['slug'] ?? '')
                    ->whereKeyNot($content->getKey())
                    ->exists()) {
                unset($root['slug']);
            }

            if (($root['thumbnail_url'] ?? null) !== null && ! is_string($root['thumbnail_url'])) {
                unset($root['thumbnail_url']);
            }

            // Un borrador nunca se publica como efecto secundario del temporizador.
            if ($content->status === EducationalContent::STATUS_DRAFT) {
                $root['status'] = EducationalContent::STATUS_DRAFT;
                $root['is_published'] = false;
            }

            $content->fill($root)->save();

            if ($content->content_type === EducationalContent::TYPE_ARTICLE) {
                $details = Arr::only($state['article_details'] ?? [], [
                    'content_text',
                    'voice_id',
                    'read_time',
                    'word_count',
                    'related_taxa',
                    'references',
                ]);

                $details['content_text'] = (string) ($details['content_text'] ?? '');
                $content->articleDetails()->updateOrCreate(['id' => $content->id], $details);
            } else {
                $details = Arr::only($state['course_details'] ?? [], [
                    'completion_points',
                    'achievement_id',
                    'has_certificate',
                    'prerequisite_content_ids',
                ]);

                $content->courseDetails()->updateOrCreate(
                    ['id' => $content->id],
                    array_merge(['completion_points' => 100], $details)
                );
            }

            return $content->refresh();
        });
    }
}
