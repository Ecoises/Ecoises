<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class ElevenLabsService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.elevenlabs.io/v1';

    public function __construct()
    {
        $this->apiKey = config('services.elevenlabs.api_key', env('ELEVENLABS_API_KEY'));
    }

    /**
     * Genera audio a partir de texto, manejando textos largos automáticamente.
     */
    public function generate(string $text, string $voiceId, string $modelId = 'eleven_multilingual_v2'): array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('ElevenLabs API key is not configured.');
        }

        if (empty(trim($text))) {
            throw new \Exception('El texto para generar audio está vacío.');
        }

        // Dividir en chunks de máximo ~1200 caracteres respetando párrafos y oraciones
        $chunks = $this->splitIntoChunks(trim($text), 1200);

        $audioParts = [];
        $wordTimestamps = [];
        $totalDuration = 0.0;
        $previousRequestIds = [];

        foreach ($chunks as $chunk) {
            $payload = [
                'text' => $chunk,
                'model_id' => $modelId,
                'voice_settings' => [
                    'stability' => 0.5,
                    'similarity_boost' => 0.75,
                ],
                'previous_request_ids' => $previousRequestIds, // Mejora continuidad prosódica
            ];

            $response = Http::timeout(90)
                ->withHeaders([
                    'xi-api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/text-to-speech/{$voiceId}/with-timestamps", $payload);

            if (!$response->successful()) {
                throw new \Exception('ElevenLabs API Error: ' . $response->body());
            }

            $data = $response->json();

            if (!isset($data['audio_base64'])) {
                throw new \Exception('Invalid response from ElevenLabs API: No audio data.');
            }

            // Guardar request_id para continuidad en próximos chunks
            if (isset($data['request_id'])) {
                $previousRequestIds[] = $data['request_id'];
                // Mantener solo los últimos 5 para no sobrecargar
                if (count($previousRequestIds) > 5) {
                    array_shift($previousRequestIds);
                }
            }

            $audioContent = base64_decode($data['audio_base64']);
            $audioParts[] = $audioContent;

            // Extraer y ajustar timestamps de palabras
            $words = $data['alignment']['words'] ?? [];
            foreach ($words as $wordData) {
                $wordTimestamps[] = [
                    'word' => $wordData['word'],
                    'start' => round($wordData['start'] + $totalDuration, 3),
                    'end'   => round($wordData['end'] + $totalDuration, 3),
                ];
            }

            // Calcular duración del chunk
            $chunkDuration = 0.0;
            if (!empty($words)) {
                $chunkDuration = end($words)['end'];
            } elseif (!empty($data['alignment']['character_end_times_seconds'])) {
                $chunkDuration = end($data['alignment']['character_end_times_seconds']);
            }

            $totalDuration += $chunkDuration;
        }

        // Concatenar todos los audios en uno solo
        $finalAudio = $this->concatenateAudioParts($audioParts);

        // Guardar archivo final
        $filename = 'audio/' . Str::uuid() . '.mp3';
        Storage::disk('public')->put($filename, $finalAudio);
        $url = Storage::url($filename);

        return [
            'audio_url'       => $url,
            'audio_timestamps'=> ['words' => $wordTimestamps], // Solo palabras, tiempos globales
            'duration'        => round($totalDuration, 2),
            'voice_id'        => $voiceId,
        ];
    }

    private function splitIntoChunks(string $text, int $maxLength): array
    {
        $paragraphs = preg_split('/\n\s*\n/', $text);
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $para) {
            $para = trim($para);

            if (strlen($current . $para) + 2 <= $maxLength) {
                $current .= ($current ? "\n\n" : '') . $para;
                continue;
            }

            if ($current) {
                $chunks[] = $current;
            }

            // Si un párrafo es muy largo, dividir por oraciones
            if (strlen($para) > $maxLength) {
                $sentences = preg_split('/(?<=[.!?])\s+/', $para);
                $subCurrent = '';

                foreach ($sentences as $sentence) {
                    if (strlen($subCurrent . $sentence) + 1 <= $maxLength) {
                        $subCurrent .= ($subCurrent ? ' ' : '') . $sentence;
                    } else {
                        if ($subCurrent) {
                            $chunks[] = trim($subCurrent);
                        }
                        $subCurrent = $sentence;
                    }
                }

                if ($subCurrent) {
                    $chunks[] = trim($subCurrent);
                }
            } else {
                $chunks[] = $para;
            }

            $current = '';
        }

        if ($current) {
            $chunks[] = $current;
        }

        return array_filter($chunks);
    }

    private function concatenateAudioParts(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        // Ruta completa a tu ffmpeg.exe (cámbiala si la carpeta es diferente)
        $ffmpegPath = 'C:\\ffmpeg\\bin\\ffmpeg.exe';

        // Verificar que el archivo exista
        if (!file_exists($ffmpegPath)) {
            throw new \Exception("FFmpeg no encontrado en: $ffmpegPath. Verifica la ruta.");
        }

        $tempFiles = [];
        $listFile = tempnam(sys_get_temp_dir(), 'audio_list');
        $outputFile = tempnam(sys_get_temp_dir(), 'final_audio') . '.mp3';

        try {
            // Crear archivos temporales para cada parte
            foreach ($parts as $i => $part) {
                $temp = tempnam(sys_get_temp_dir(), 'part') . '.mp3';
                file_put_contents($temp, $part);
                $tempFiles[] = $temp;
                file_put_contents($listFile, "file '$temp'\n", FILE_APPEND);
            }

            // Ejecutar FFmpeg con ruta absoluta
            $command = "\"$ffmpegPath\" -f concat -safe 0 -i \"$listFile\" -c copy \"$outputFile\" 2>&1";
            
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \Exception('Error al concatenar con FFmpeg: ' . implode("\n", $output));
            }

            if (!file_exists($outputFile)) {
                throw new \Exception('FFmpeg no generó el archivo de salida.');
            }

            $finalAudio = file_get_contents($outputFile);

        } finally {
            // Siempre limpiar archivos temporales
            foreach ($tempFiles as $file) {
                if (file_exists($file)) @unlink($file);
            }
            if (file_exists($listFile)) @unlink($listFile);
            if (file_exists($outputFile)) @unlink($outputFile);
        }

        return $finalAudio;
    }

    public function getVoices(): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        try {
            $response = Http::withHeaders(['xi-api-key' => $this->apiKey])
                ->get("{$this->baseUrl}/voices");

            if ($response->successful()) {
                return $response->json()['voices'] ?? [];
            }
        } catch (\Exception $e) {
            // Silenciar errores
        }

        return [];
    }
}