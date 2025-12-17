<div class="space-y-3 p-4 bg-gray-50 rounded-lg border border-gray-200">
    <label class="text-sm font-medium text-gray-700">
        {{ __('Preview de la voz') }}
    </label>
    
    <audio id="voice-preview" 
           controls 
           class="w-full rounded-lg border border-gray-300 bg-white"
           style="display: none;">
    </audio>
    
    <p id="no-preview" class="text-sm text-gray-500 italic">
        Selecciona una voz para reproducir el preview
    </p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const voiceSelect = document.querySelector('[data-state-path*="voice_id"]');
    const audioPreview = document.getElementById('voice-preview');
    const noPreview = document.getElementById('no-preview');
    
    const voicePreviewUrls = @json($voicePreviewUrls ??  []);
    
    if (voiceSelect) {
        voiceSelect. addEventListener('change', function() {
            const selectedVoiceId = this.value;
            const previewUrl = voicePreviewUrls[selectedVoiceId];
            
            if (previewUrl) {
                audioPreview.src = previewUrl;
                audioPreview.style.display = 'block';
                noPreview.style.display = 'none';
            } else {
                audioPreview.style.display = 'none';
                noPreview.style.display = 'block';
            }
        });
        
        // Trigger change event on load if voice is already selected
        if (voiceSelect.value) {
            voiceSelect.dispatchEvent(new Event('change'));
        }
    }
});
</script>