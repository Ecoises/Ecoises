<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ElevenLabsService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.elevenlabs.io/v1';

    public function __construct()
    {
        $this->apiKey = config('services.elevenlabs.api_key', env('ELEVENLABS_API_KEY'));
    }

    /**
     * Generate audio from text with timestamps.
     *
     * @param string $text
     * @param string $voiceId
     * @param string $modelId
     * @return array
     * @throws \Exception
     */
    public function generate(string $text, string $voiceId, string $modelId = 'eleven_multilingual_v2'): array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('ElevenLabs API key is not configured.');
        }

        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/text-to-speech/{$voiceId}/with-timestamps", [
            'text' => $text,
            'model_id' => $modelId,
            'voice_settings' => [
                'stability' => 0.5,
                'similarity_boost' => 0.75,
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception('ElevenLabs API Error: ' . $response->body());
        }

        $data = $response->json();
        
        // The response contains base64 audio and alignment data
        if (!isset($data['audio_base64'])) {
            throw new \Exception('Invalid response from ElevenLabs API: No audio data.');
        }

        // Decode audio
        $audioContent = base64_decode($data['audio_base64']);
        
        // Generate filename
        $filename = 'audio/' . Str::uuid() . '.mp3';
        
        // Store file in public disk
        Storage::disk('public')->put($filename, $audioContent);
        
        // Get URL
        $url = Storage::url($filename);
        
        // Extract alignment (timestamps)
        $alignment = $data['alignment'] ?? [];
        
        // Calculate total duration in seconds
        $duration = 0;
        if (!empty($alignment['char_start_times_ms']) && !empty($alignment['char_durations_ms'])) {
            $lastIndex = count($alignment['char_start_times_ms']) - 1;
            $durationMs = $alignment['char_start_times_ms'][$lastIndex] + $alignment['char_durations_ms'][$lastIndex];
            $duration = round($durationMs / 1000, 2);
        }

        return [
            'audio_url' => $url,
            'audio_timestamps' => $alignment,
            'duration' => $duration,
            'voice_id' => $voiceId,
        ];
    }
    
    /**
     * Get available voices.
     * 
     * @return array
     */
    public function getVoices(): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'xi-api-key' => $this->apiKey,
            ])->get("{$this->baseUrl}/voices");
            
            if ($response->successful()) {
                return $response->json()['voices'] ?? [];
            }
        } catch (\Exception $e) {
            // Log error or just return empty
        }
        
        return [];
    }
}
