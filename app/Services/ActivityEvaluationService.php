<?php

namespace App\Services;

use App\Models\Activity;
use Illuminate\Validation\ValidationException;

class ActivityEvaluationService
{
    /** @return array<string, mixed> */
    public function publicPayload(Activity $activity): array
    {
        $payload = [
            'id' => $activity->id,
            'activity_type' => $activity->activity_type,
            'title' => $activity->title,
            'instruction' => $activity->instructions,
            'max_points' => (int) $activity->max_points,
            'attempts_allowed' => (int) $activity->attempts_allowed,
            'is_mandatory' => (bool) $activity->is_mandatory,
        ];

        return array_merge($payload, match ($activity->activity_type) {
            'quiz_multiple' => $this->multipleChoicePayload($activity),
            'drag_drop' => $this->dragDropPayload($activity),
            'matching' => $this->matchingPayload($activity),
            default => [],
        });
    }

    /** @return array{is_correct: bool, feedback: ?string} */
    public function evaluate(Activity $activity, array $answers): array
    {
        return match ($activity->activity_type) {
            'quiz_multiple' => $this->evaluateMultipleChoice($activity, $answers),
            'quiz_true_false' => $this->evaluateTrueFalse($activity, $answers),
            'drag_drop' => $this->evaluateDragDrop($activity, $answers),
            'matching' => $this->evaluateMatching($activity, $answers),
            default => throw ValidationException::withMessages([
                'activity' => 'Este tipo de actividad todavía no admite evaluación automática.',
            ]),
        };
    }

    /** @return array<string, mixed> */
    private function multipleChoicePayload(Activity $activity): array
    {
        $options = collect($activity->content_data['options'] ?? [])
            ->values()
            ->map(fn (array $option, int $index): array => [
                'id' => $this->token($activity, "option:{$index}"),
                'text' => (string) ($option['text'] ?? ''),
            ])
            ->all();

        return ['options' => $options];
    }

    /** @return array<string, mixed> */
    private function dragDropPayload(Activity $activity): array
    {
        $categories = [];
        $items = [];

        foreach (array_values($activity->content_data['categories'] ?? []) as $categoryIndex => $category) {
            $categoryToken = $this->token($activity, "category:{$categoryIndex}");
            $categories[] = [
                'id' => $categoryToken,
                'name' => (string) ($category['name'] ?? ''),
            ];

            foreach (array_values($category['items'] ?? []) as $itemIndex => $label) {
                $items[] = [
                    'id' => $this->token($activity, "category:{$categoryIndex}:item:{$itemIndex}"),
                    'label' => (string) $label,
                ];
            }
        }

        shuffle($items);

        return ['categories' => $categories, 'items' => $items];
    }

    /** @return array<string, mixed> */
    private function matchingPayload(Activity $activity): array
    {
        $terms = [];
        $matches = [];

        foreach (array_values($activity->content_data['pairs'] ?? []) as $index => $pair) {
            $terms[] = [
                'id' => $this->token($activity, "term:{$index}"),
                'text' => (string) ($pair['term'] ?? $pair['element'] ?? ''),
            ];
            $matches[] = [
                'id' => $this->token($activity, "match:{$index}"),
                'text' => (string) ($pair['match'] ?? $pair['target'] ?? ''),
            ];
        }

        shuffle($terms);
        shuffle($matches);

        return ['terms' => $terms, 'matches' => $matches];
    }

    /** @return array{is_correct: bool, feedback: ?string} */
    private function evaluateMultipleChoice(Activity $activity, array $answers): array
    {
        $selectedToken = $answers['option_id'] ?? null;

        foreach (array_values($activity->content_data['options'] ?? []) as $index => $option) {
            if (is_string($selectedToken) && hash_equals($this->token($activity, "option:{$index}"), $selectedToken)) {
                return [
                    'is_correct' => (bool) ($option['is_correct'] ?? $option['isCorrect'] ?? false),
                    'feedback' => $option['feedback'] ?? null,
                ];
            }
        }

        throw ValidationException::withMessages(['answers.option_id' => 'La opción seleccionada no es válida.']);
    }

    /** @return array{is_correct: bool, feedback: ?string} */
    private function evaluateTrueFalse(Activity $activity, array $answers): array
    {
        if (! array_key_exists('answer', $answers) || ! is_bool($answers['answer'])) {
            throw ValidationException::withMessages(['answers.answer' => 'Selecciona verdadero o falso.']);
        }

        $expected = filter_var(
            $activity->content_data['correct_answer'] ?? $activity->content_data['is_true'] ?? false,
            FILTER_VALIDATE_BOOL,
        );
        $isCorrect = $answers['answer'] === $expected;

        return [
            'is_correct' => $isCorrect,
            'feedback' => $isCorrect
                ? ($activity->content_data['feedback_correct'] ?? null)
                : ($activity->content_data['feedback_incorrect'] ?? null),
        ];
    }

    /** @return array{is_correct: bool, feedback: ?string} */
    private function evaluateDragDrop(Activity $activity, array $answers): array
    {
        $placements = $answers['placements'] ?? null;

        if (! is_array($placements)) {
            throw ValidationException::withMessages(['answers.placements' => 'Envía la clasificación completa.']);
        }

        $expected = [];
        foreach (array_values($activity->content_data['categories'] ?? []) as $categoryIndex => $category) {
            $categoryToken = $this->token($activity, "category:{$categoryIndex}");
            foreach (array_values($category['items'] ?? []) as $itemIndex => $label) {
                $expected[$this->token($activity, "category:{$categoryIndex}:item:{$itemIndex}")] = $categoryToken;
            }
        }

        $isCorrect = count($placements) === count($expected);
        foreach ($expected as $itemToken => $categoryToken) {
            $isCorrect = $isCorrect
                && isset($placements[$itemToken])
                && is_string($placements[$itemToken])
                && hash_equals($categoryToken, $placements[$itemToken]);
        }

        return ['is_correct' => $isCorrect, 'feedback' => null];
    }

    /** @return array{is_correct: bool, feedback: ?string} */
    private function evaluateMatching(Activity $activity, array $answers): array
    {
        $connections = $answers['connections'] ?? null;

        if (! is_array($connections)) {
            throw ValidationException::withMessages(['answers.connections' => 'Envía todas las conexiones.']);
        }

        $pairs = array_values($activity->content_data['pairs'] ?? []);
        $isCorrect = count($connections) === count($pairs);

        foreach ($pairs as $index => $pair) {
            $termToken = $this->token($activity, "term:{$index}");
            $matchToken = $this->token($activity, "match:{$index}");
            $isCorrect = $isCorrect
                && isset($connections[$termToken])
                && is_string($connections[$termToken])
                && hash_equals($matchToken, $connections[$termToken]);
        }

        return ['is_correct' => $isCorrect, 'feedback' => null];
    }

    private function token(Activity $activity, string $value): string
    {
        return hash_hmac('sha256', "activity:{$activity->id}:{$value}", (string) config('app.key'));
    }
}
