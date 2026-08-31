<?php

namespace App\Services;

use App\Models\EducationalContent;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EducationalDraftService
{
    /**
     * Crea el registro persistente antes de mostrar el editor. De esta forma,
     * adjuntos, lecciones y trabajos de audio siempre tienen un padre real.
     */
    public function create(User $author): EducationalContent
    {
        return DB::transaction(function () use ($author): EducationalContent {
            return EducationalContent::create([
                'content_type' => null,
                'title' => 'Borrador sin título',
                'slug' => 'borrador-'.Str::lower(Str::random(16)),
                'author_id' => $author->id,
                'difficulty_level' => 'principiante',
                'estimated_duration' => 0,
                'status' => EducationalContent::STATUS_DRAFT,
                'is_published' => false,
                'is_featured' => false,
            ]);

        });
    }

    /**
     * El tipo se elige una sola vez. Esto mantiene la estructura CTI válida y
     * evita que un artículo conserve lecciones o detalles propios de un curso.
     */
    public function assignType(EducationalContent $content, ?string $type): EducationalContent
    {
        if (blank($type)) {
            return $content;
        }

        if (! in_array($type, EducationalContent::getTypeValues(), true)) {
            throw ValidationException::withMessages([
                'content_type' => 'El tipo de contenido seleccionado no es válido.',
            ]);
        }

        return DB::transaction(function () use ($content, $type): EducationalContent {
            $content->refresh();

            if (filled($content->content_type) && $content->content_type !== $type) {
                throw ValidationException::withMessages([
                    'content_type' => 'El tipo queda fijado al iniciar la estructura. Crea otro borrador para usar un tipo diferente.',
                ]);
            }

            if (blank($content->content_type)) {
                $content->forceFill(['content_type' => $type])->save();

                if ($type === EducationalContent::TYPE_COURSE) {
                    $content->courseDetails()->firstOrCreate(
                        ['id' => $content->id],
                        ['completion_points' => 100]
                    );
                } elseif ($type === EducationalContent::TYPE_ARTICLE) {
                    $content->articleDetails()->firstOrCreate(
                        ['id' => $content->id],
                        ['content_text' => '']
                    );
                }
            }

            return $content->refresh();
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
            $content = $this->assignType($content, $state['content_type'] ?? null);

            $root = Arr::only($state, [
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
            } elseif ($content->content_type === EducationalContent::TYPE_COURSE) {
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
