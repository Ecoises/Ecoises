<?php

namespace App\Services\Biodiversity;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Traduce al español (con Gemini) los textos de EOL que llegan en otro idioma
 * (hábitat, dieta, ecología e historia natural).
 *
 *  - Se traduce UNA vez, al guardar el perfil de EOL: las fichas leen el texto
 *    ya traducido de la base de datos.
 *  - Todos los textos de una especie van en una sola llamada.
 *  - El original se conserva en `original_text` / `original_language`.
 */
class EcologyTranslationService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public const FIELDS = ['habitat', 'diet', 'ecology', 'natural_history'];

    /** Campos del perfil que tienen texto en un idioma distinto al español. */
    public function pendingFields(array $profile): array
    {
        return array_values(array_filter(self::FIELDS, fn (string $field) => ! empty($profile[$field]['text'])
            && ($profile[$field]['language'] ?? null) !== 'es'
            && empty($profile[$field]['translated'])));
    }

    /**
     * Devuelve el perfil con los textos traducidos. Si no hay clave o la IA
     * falla, devuelve el perfil sin cambios (se reintenta en la próxima sincronización).
     */
    public function translateProfile(array $profile): array
    {
        $fields = $this->pendingFields($profile);
        $key = config('services.google.gemini_key');
        if ($fields === [] || ! $key) {
            return $profile;
        }

        $texts = collect($fields)->mapWithKeys(fn (string $field) => [$field => $profile[$field]['text']])->all();
        $model = config('services.google.ecology_model', 'gemini-flash-lite-latest');

        $response = Http::timeout(40)->post(sprintf(self::ENDPOINT, $model).'?key='.$key, [
            'contents' => [['parts' => [['text' => $this->prompt($texts)]]]],
            'generationConfig' => ['temperature' => 0.1, 'responseMimeType' => 'application/json'],
        ]);

        $translated = json_decode((string) $response->json('candidates.0.content.parts.0.text'), true);
        if (! $response->successful() || ! is_array($translated)) {
            Log::warning('Gemini no tradujo los textos de EOL', ['status' => $response->status(), 'fields' => $fields]);

            return $profile;
        }

        foreach ($fields as $field) {
            $text = trim(strip_tags((string) ($translated[$field] ?? '')));
            if ($text === '') {
                continue;
            }

            $profile[$field] = [
                ...$profile[$field],
                'text' => $text,
                'language' => 'es',
                'translated' => true,
                'original_text' => $profile[$field]['text'],
                'original_language' => $profile[$field]['language'] ?? null,
            ];
        }

        return $profile;
    }

    private function prompt(array $texts): string
    {
        return implode("\n", [
            'Traduce al español neutro (Colombia) cada texto de este JSON sobre una especie. Si un texto ya está en español, devuélvelo igual.',
            'Reglas: conserva el significado exacto, sin resumir, añadir ni omitir datos. Deja los nombres científicos tal cual (en latín). Usa nombres comunes en español solo si son de uso habitual.',
            'Responde SOLO con un JSON que tenga las mismas claves y el texto traducido como valor.',
            '',
            json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
    }
}
