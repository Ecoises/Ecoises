<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiAudioService
{
    protected string $geminiKey;

    public function __construct()
    {
        $this->geminiKey = config('services.google.gemini_key');
    }

    public function generateGeminiAudio(string $text, string $voiceId): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-tts:generateContent?key={$this->geminiKey}";

        // Incluimos el estilo directamente en el texto como sugiere el ejemplo de Google
        $styledText = "Narra con entusiasmo y naturalidad profesional: " . $text;

        $response = Http::timeout(150)->post($url, [
            "contents" => [[
                "parts" => [["text" => $styledText]]
            ]],
            "generationConfig" => [
                "responseModalities" => ["AUDIO"],
                "speechConfig" => [
                    "voiceConfig" => [
                        "prebuiltVoiceConfig" => [
                            "voiceName" => $voiceId // Ejemplo: "Kore", "Aoede", "Charon"
                        ]
                    ]
                ]
            ]
        ]);

        $data = $response->json();

        if (!isset($data['candidates'][0]['content']['parts'][0]['inlineData']['data'])) {
            Log::error("Error Gemini TTS: " . json_encode($data));
            throw new \Exception("La API no devolvió datos de audio.");
        }

        // Devolvemos el binario (PCM)
        return base64_decode($data['candidates'][0]['content']['parts'][0]['inlineData']['data']);
    }
}