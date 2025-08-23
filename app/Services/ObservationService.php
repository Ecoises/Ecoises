<?php

namespace App\Services;

use App\Models\Observation;
use App\Models\ObservationPhoto;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class ObservationService
{
    /**
     * Crear una nueva observación
     */
    public function createObservation(array $data): Observation
    {
        $observation = Observation::create($data);

        // Actualizar observation_count en taxa
        if ($data['taxon_id']) {
            \App\Models\Taxa::where('id', $data['taxon_id'])
                ->increment('observation_count')
                ->update(['last_observed_at' => now()]);
        }

        return $observation;
    }

    /**
     * Subir una foto para una observación
     */
    public function uploadPhoto(Observation $observation, UploadedFile $photo, ?string $caption = null): ObservationPhoto
    {
        $path = $photo->store('observation_photos', 'public');
        $fileSize = $photo->getSize();
        $imageDimensions = getimagesize($photo->getRealPath());

        $photoCount = $observation->photos()->count();
        $isPrimary = $photoCount === 0; // Primera foto es primaria por defecto

        $observationPhoto = ObservationPhoto::create([
            'observation_id' => $observation->id,
            'photo_url' => Storage::url($path),
            'is_primary' => $isPrimary,
            'caption' => $caption,
            'photo_order' => $photoCount + 1,
            'file_size' => $fileSize,
            'image_width' => $imageDimensions[0],
            'image_height' => $imageDimensions[1]
        ]);

        // Recalcular quality_score
        $this->updateQualityScore($observation);

        return $observationPhoto;
    }

    /**
     * Eliminar una foto
     */
    public function deletePhoto(ObservationPhoto $photo): void
    {
        Storage::disk('public')->delete(str_replace(Storage::url(''), '', $photo->photo_url));
        $observation = $photo->observation;

        $photo->delete();

        // Si era primaria, establecer otra como primaria
        if ($photo->is_primary) {
            $newPrimary = $observation->photos()->first();
            if ($newPrimary) {
                $newPrimary->update(['is_primary' => true]);
            }
        }

        // Recalcular quality_score
        $this->updateQualityScore($observation);
    }

    /**
     * Establecer foto primaria
     */
    public function setPrimaryPhoto(Observation $observation, ObservationPhoto $photo): void
    {
        $observation->photos()->update(['is_primary' => false]);
        $photo->update(['is_primary' => true]);

        // Recalcular quality_score
        $this->updateQualityScore($observation);
    }

    /**
     * Actualizar quality_score basado en fotos y datos
     */
    protected function updateQualityScore(Observation $observation): void
    {
        $score = 0;
        $photoCount = $observation->photos()->count();
        $hasTaxon = !is_null($observation->taxon_id);
        $hasDescription = !empty($observation->description);
        $hasEnvironmentalData = !is_null($observation->temperature) || !is_null($observation->humidity);

        if ($photoCount > 0) $score += $photoCount * 0.5; // 0.5 por foto
        if ($hasTaxon) $score += 1.5; // Identificación suma más
        if ($hasDescription) $score += 0.5;
        if ($hasEnvironmentalData) $score += 0.5;

        // Máximo 5.0
        $score = min($score, 5.0);

        $observation->update(['quality_score' => $score]);
    }
}