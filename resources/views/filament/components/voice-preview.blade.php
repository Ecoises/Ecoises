@php
    $voicePreviewUrls = $voicePreviewUrls ?? [];
    $selectedVoiceId = $get('voice_id');
    $previewUrl = $voicePreviewUrls[$selectedVoiceId] ?? null;
@endphp

<div class="space-y-3 p-4 bg-gray-50 rounded-lg border border-gray-200"
     x-data="{
         audioSrc: '{{ $previewUrl }}',
         init() {
             this.$watch('$wire.data.lessons.{{ $getStatePath() }}', value => {
                 // Component logic handles updates via Blade re-render due to 'live()' on Select
             });
         }
     }">
    
    <label class="text-sm font-medium text-gray-700">
        {{ __('Preview de la voz') }}
    </label>

    @if ($previewUrl)
        <audio controls class="w-full rounded-lg border border-gray-300 bg-white block">
            <source src="{{ $previewUrl }}" type="audio/mpeg">
            Your browser does not support the audio element.
        </audio>
    @else
        <p class="text-sm text-gray-500 italic block">
            Selecciona una voz para reproducir el preview
        </p>
    @endif
</div>