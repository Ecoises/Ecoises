<?php

namespace App\Jobs;

use App\Services\GeminiAudioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessAudioTimestamps implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 150; // Tiempo extra para procesos largos

    public function __construct(
        protected $model,
        protected string $text,
        protected string $relativeAudioPath,
        protected string $prefix
    ) {}

    public function handle(GeminiAudioService $service)
    {
        $fullPath = Storage::disk('public')->path($this->relativeAudioPath);
        
        $result = $service->getForcedAlignment($this->text, $fullPath);
        
        $dot = filled($this->prefix) ? '.' : '';
        $timestampField = filled($this->prefix) ? "{$this->prefix}->audio_timestamps" : "audio_timestamps";

        // Guardamos los timestamps en el modelo (JSON)
        $this->model->update([
            $timestampField => $result['alignment']['words'] ?? []
        ]);
    }
}