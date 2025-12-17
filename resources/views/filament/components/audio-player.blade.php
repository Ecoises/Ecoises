@php
    $audioUrl = $get('audio_url');
@endphp

@if ($audioUrl)
    <div class="mt-4 p-4 bg-green-50 rounded-lg border border-green-200">
        <label class="block text-sm font-medium text-green-700 mb-2">
            {{ __('Audio Generado') }}
        </label>
        <audio controls class="w-full rounded-lg border border-green-300 bg-white shadow-sm">
            <source src="{{ $audioUrl }}" type="audio/mpeg">
            Su navegador no soporta el elemento audio.
        </audio>
    </div>
@endif
