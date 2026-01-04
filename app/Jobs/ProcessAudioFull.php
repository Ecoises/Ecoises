<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\GeminiAudioService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessAudioFull implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected $model,
        protected string $text,
        protected string $voiceId,
        protected User $user,
        protected string $prefix = ''
    ) {}

    public function handle(GeminiAudioService $service)
    {
        try {
            // 1. Generar audio
            $rawPcm = $service->generateGeminiAudio($this->text, $this->voiceId);
            $wavContent = $this->packPcmToWav($rawPcm, 24000, 1, 16);

            // 2. Guardar archivo
            $filename = 'audio/' . Str::uuid() . '.wav';
            Storage::disk('public')->put($filename, $wavContent);
            $url = Storage::url($filename);

            // 3. CORRECCIÓN: Determinar el modelo correcto donde guardar
            $target = $this->determineTarget();

            if (!$target) {
                throw new \Exception("No se pudo determinar el destino para el audio.");
            }

            // 4. Guardar URL según el tipo de modelo
            if ($this->prefix === 'article_details') {
                // Para artículos: guardar en article_details (JSON)
                $articleDetails = $target->articleDetails ?? new \App\Models\ArticleDetails(['educational_content_id' => $target->id]);
                $articleDetails->audio_url = $url;
                $articleDetails->save();
                
                Log::info("Audio guardado en article_details para content ID: {$target->id}");
            } else {
                // Para lecciones: guardar directamente en lessons
                $target->forceFill(['audio_url' => $url])->save();
                
                Log::info("Audio guardado en lesson ID: {$target->id}");
            }

            // 5. Notificación
            Notification::make()
                ->title('Audio generado con éxito')
                ->body('El audio narrado está listo.')
                ->success()
                ->sendToDatabase($this->user);

        } catch (\Throwable $e) {
            Log::error("Error Job Audio: " . $e->getMessage(), [
                'model_type' => get_class($this->model),
                'model_id' => $this->model->id ?? 'N/A',
                'prefix' => $this->prefix,
                'trace' => $e->getTraceAsString()
            ]);

            Notification::make()
                ->title('Error al generar audio')
                ->body('Por favor, intenta nuevamente.')
                ->danger()
                ->sendToDatabase($this->user);
        }
    }

    /**
     * Determina el modelo correcto donde debe guardarse el audio
     */
    private function determineTarget()
    {
        // Si el prefix es 'article_details', el modelo es EducationalContent
        if ($this->prefix === 'article_details') {
            return $this->model;
        }

        // Si no hay prefix, asumimos que el modelo YA es una Lesson
        // (esto debe venir correctamente desde el formulario)
        return $this->model;
    }

    /**
     * Crea un encabezado WAV para datos PCM lineales
     */
    private function packPcmToWav($pcmData, $sampleRate, $channels, $bitsPerSample)
    {
        $dataSize = strlen($pcmData);
        $header = pack('N', 0x52494646); // "RIFF"
        $header .= pack('V', 36 + $dataSize); // ChunkSize
        $header .= pack('N', 0x57415645); // "WAVE"
        $header .= pack('N', 0x666d7420); // "fmt "
        $header .= pack('V', 16); // Subchunk1Size
        $header .= pack('v', 1); // AudioFormat (1 = PCM)
        $header .= pack('v', $channels); // NumChannels
        $header .= pack('V', $sampleRate); // SampleRate
        $header .= pack('V', $sampleRate * $channels * ($bitsPerSample / 8)); // ByteRate
        $header .= pack('v', $channels * ($bitsPerSample / 8)); // BlockAlign
        $header .= pack('v', $bitsPerSample); // BitsPerSample
        $header .= pack('N', 0x64617461); // "data"
        $header .= pack('V', $dataSize); // Subchunk2Size

        return $header . $pcmData;
    }
}