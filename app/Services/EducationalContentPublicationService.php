<?php

namespace App\Services;

use App\Models\EducationalContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EducationalContentPublicationService
{
    public function __construct(
        private readonly EducationalWorkflowNotificationService $notifications,
    ) {}

    public function submitForReview(EducationalContent $content): EducationalContent
    {
        $content->loadMissing(['articleDetails', 'courseDetails', 'lessons', 'assets']);
        $errors = $this->validationErrors($content);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $content->forceFill([
            'status' => EducationalContent::STATUS_PENDING,
            'is_published' => false,
            'published_at' => null,
        ])->save();

        $this->notifications->submittedForReview($content->refresh()->load('author'));

        return $content->refresh();
    }

    public function markReviewed(EducationalContent $content): EducationalContent
    {
        if ($content->status !== EducationalContent::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Solo un contenido pendiente puede marcarse como revisado.',
            ]);
        }

        $content->forceFill(['status' => EducationalContent::STATUS_REVIEWED])->save();

        $this->notifications->reviewed($content->refresh()->load('author'));

        return $content->refresh();
    }

    public function returnToDraft(EducationalContent $content): EducationalContent
    {
        $content->forceFill([
            'status' => EducationalContent::STATUS_DRAFT,
            'is_published' => false,
            'published_at' => null,
        ])->save();

        $this->notifications->returnedToDraft($content->refresh()->load('author'));

        return $content->refresh();
    }

    public function publish(EducationalContent $content): EducationalContent
    {
        $content->loadMissing(['articleDetails', 'courseDetails', 'lessons', 'assets']);

        $errors = $this->validationErrors($content);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $published = DB::transaction(function () use ($content): EducationalContent {
            if ($content->isCourse()) {
                $content->lessons()->update([
                    'status' => EducationalContent::STATUS_PUBLISHED,
                    'is_published' => true,
                ]);
            }

            $content->forceFill([
                'status' => EducationalContent::STATUS_PUBLISHED,
                'is_published' => true,
                'published_at' => now(),
            ])->save();

            return $content->refresh();
        });

        $this->notifications->published($published->load('author'));

        return $published;
    }

    public function unpublish(EducationalContent $content): EducationalContent
    {
        $unpublished = DB::transaction(function () use ($content): EducationalContent {
            if ($content->isCourse()) {
                $content->lessons()->update([
                    'status' => EducationalContent::STATUS_DRAFT,
                    'is_published' => false,
                ]);
            }

            $content->forceFill([
                'status' => EducationalContent::STATUS_DRAFT,
                'is_published' => false,
                'published_at' => null,
            ])->save();

            return $content->refresh();
        });

        $this->notifications->unpublished($unpublished->load('author'));

        return $unpublished;
    }

    /** @return array<string, array<int, string>> */
    public function validationErrors(EducationalContent $content): array
    {
        $errors = [];

        if (! in_array($content->content_type, EducationalContent::getTypeValues(), true)) {
            $errors['content_type'][] = 'Selecciona un tipo de contenido antes de publicar.';
        }

        if (blank($content->title) || Str::startsWith($content->title, 'Borrador sin título')) {
            $errors['title'][] = 'Escribe un título definitivo antes de publicar.';
        }

        if (blank(strip_tags((string) $content->description))) {
            $errors['description'][] = 'Añade una descripción educativa antes de publicar.';
        }

        if ($content->isCourse()) {
            if (! $content->courseDetails) {
                $errors['course_details'][] = 'El curso no tiene su configuración general.';
            }

            if ($content->lessons->isEmpty()) {
                $errors['lessons'][] = 'El curso necesita al menos una lección.';
            }

            foreach ($content->lessons as $index => $lesson) {
                if (blank($lesson->title) || blank(strip_tags((string) $lesson->content_text))) {
                    $errors["lessons.{$index}"][] = 'Cada lección necesita título y contenido.';
                }
            }
        }

        if ($content->isArticle() && blank(strip_tags((string) $content->articleDetails?->content_text))) {
            $errors['article_details.content_text'][] = 'El artículo necesita contenido antes de publicarse.';
        }

        if ($content->isResource() && $content->assets->isEmpty()) {
            $errors['assets'][] = 'El recurso necesita al menos un archivo, una imagen, una infografía o un enlace.';
        }

        return $errors;
    }
}
