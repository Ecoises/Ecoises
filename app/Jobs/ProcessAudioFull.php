<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Lesson;
use App\Models\EducationalContent;
use App\Models\ArticleDetails;
use App\Services\GeminiAudioService;
use Filament\Actions\Action;
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

    public $tries = 3;
    public $timeout = 360;

    public function __construct(
        protected $model,
        protected string $text,
        protected string $voiceId,
        protected User $user,
        protected string $prefix = '',
        protected array $contextInfo = []
    ) {}

    public function handle(GeminiAudioService $service)
    {
        try {
            Log::info("Generando audio", [
                'context' => $this->contextInfo,
                'voice' => $this->voiceId
            ]);

            // 1. Generar audio
            $rawPcm = $service->generateGeminiAudio($this->text, $this->voiceId);
            $wavContent = $this->packPcmToWav($rawPcm, 24000, 1, 16);

            // 2. Guardar archivo
            $filename = 'audio/' . Str::uuid() . '.wav';
            Storage::disk('public')->put($filename, $wavContent);
            $url = Storage::url($filename);

            // 3. Guardar en base de datos
            if ($this->prefix === 'article_details') {
                $articleDetails = ArticleDetails::firstOrCreate(
                    ['id' => $this->model->id],
                    ['content_text' => '', 'read_time' => 0]
                );
                $articleDetails->update([
                    'audio_url' => $url,
                    'voice_id' => $this->voiceId
                ]);
            } else {
                $this->model->update([
                    'audio_url' => $url,
                    'voice_id' => $this->voiceId
                ]);
            }

            // 4. Notificación de éxito
            $title = $this->contextInfo['type'] === 'article'
                ? "Audio del artículo \"{$this->contextInfo['title']}\" listo"
                : "Audio de la lección \"{$this->contextInfo['lesson_title']}\" listo";

            $body = $this->contextInfo['type'] === 'article'
                ? "Ya puedes escuchar la narración de tu artículo."
                : "La lección del curso \"{$this->contextInfo['educational_content_title']}\" ya tiene su audio.";

            Notification::make()
                ->title($title)
                ->body($body)
                ->success()
                ->actions([
                    Action::make('listen')
                        ->label('Escuchar ahora')
                        ->url($url)
                        ->openUrlInNewTab(),
                ])
                ->sendToDatabase($this->user);

            Log::info("Audio generado exitosamente");

        } catch (\Throwable $e) {
            Log::error("Error al generar audio: " . $e->getMessage());
            throw $e;
        }
    }

    private function packPcmToWav($pcmData, $sampleRate, $channels, $bitsPerSample): string
    {
        $dataSize = strlen($pcmData);
        $header = pack('N', 0x52494646);
        $header .= pack('V', 36 + $dataSize);
        $header .= pack('N', 0x57415645);
        $header .= pack('N', 0x666d7420);
        $header .= pack('V', 16);
        $header .= pack('v', 1);
        $header .= pack('v', $channels);
        $header .= pack('V', $sampleRate);
        $header .= pack('V', $sampleRate * $channels * ($bitsPerSample / 8));
        $header .= pack('v', $channels * ($bitsPerSample / 8));
        $header .= pack('v', $bitsPerSample);
        $header .= pack('N', 0x64617461);
        $header .= pack('V', $dataSize);

        return $header . $pcmData;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Job falló definitivamente", [
            'error' => $exception->getMessage(),
            'context' => $this->contextInfo
        ]);

        Notification::make()
            ->title('No se pudo generar el audio')
            ->body('El proceso falló después de varios intentos.')
            ->danger()
            ->sendToDatabase($this->user);
    }
}