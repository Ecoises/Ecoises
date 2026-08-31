<?php

namespace Tests\Unit;

use App\Models\Activity;
use App\Services\ActivityEvaluationService;
use Tests\TestCase;

class ActivityEvaluationServiceTest extends TestCase
{
    public function test_it_evaluates_true_false_without_exposing_the_answer(): void
    {
        $activity = $this->activity('quiz_true_false', [
            'correct_answer' => 'true',
            'feedback_correct' => 'Exacto',
            'feedback_incorrect' => 'Revisa la explicación',
        ]);
        $service = app(ActivityEvaluationService::class);

        $this->assertArrayNotHasKey('correct_answer', $service->publicPayload($activity));
        $this->assertTrue($service->evaluate($activity, ['answer' => true])['is_correct']);
        $this->assertFalse($service->evaluate($activity, ['answer' => false])['is_correct']);
    }

    public function test_it_evaluates_drag_drop_with_opaque_item_and_category_ids(): void
    {
        $activity = $this->activity('drag_drop', [
            'categories' => [
                ['name' => 'Aves', 'items' => ['Garza']],
                ['name' => 'Mamíferos', 'items' => ['Nutria']],
            ],
        ]);
        $service = app(ActivityEvaluationService::class);
        $payload = $service->publicPayload($activity);
        $categoryIds = collect($payload['categories'])->pluck('id', 'name');
        $placements = collect($payload['items'])->mapWithKeys(fn (array $item): array => [
            $item['id'] => $categoryIds[$item['label'] === 'Garza' ? 'Aves' : 'Mamíferos'],
        ])->all();

        $this->assertTrue($service->evaluate($activity, ['placements' => $placements])['is_correct']);

        $firstItem = array_key_first($placements);
        $placements[$firstItem] = $placements[$firstItem] === $categoryIds['Aves']
            ? $categoryIds['Mamíferos']
            : $categoryIds['Aves'];
        $this->assertFalse($service->evaluate($activity, ['placements' => $placements])['is_correct']);
    }

    public function test_it_evaluates_matching_without_reusing_pair_ids(): void
    {
        $activity = $this->activity('matching', [
            'pairs' => [
                ['term' => 'Polinización', 'match' => 'Transferencia de polen'],
                ['term' => 'Dispersión', 'match' => 'Movimiento de semillas'],
            ],
        ]);
        $service = app(ActivityEvaluationService::class);
        $payload = $service->publicPayload($activity);
        $matchIds = collect($payload['matches'])->pluck('id', 'text');
        $connections = collect($payload['terms'])->mapWithKeys(fn (array $term): array => [
            $term['id'] => $matchIds[$term['text'] === 'Polinización'
                ? 'Transferencia de polen'
                : 'Movimiento de semillas'],
        ])->all();

        $this->assertNotSame($payload['terms'][0]['id'], $payload['matches'][0]['id']);
        $this->assertTrue($service->evaluate($activity, ['connections' => $connections])['is_correct']);
    }

    private function activity(string $type, array $contentData): Activity
    {
        $activity = new Activity([
            'title' => 'Actividad de prueba',
            'activity_type' => $type,
            'content_data' => $contentData,
            'max_points' => 10,
            'attempts_allowed' => 3,
            'is_mandatory' => true,
        ]);
        $activity->id = 99;

        return $activity;
    }
}
