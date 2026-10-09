<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\ChallengeParticipation;
use App\Models\Observation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Retos de campo. La lista es pública; si la petición trae token se añade
 * el progreso del usuario en cada reto.
 */
class ChallengeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $challenges = Challenge::available()
            ->with('taxon.apiReferences')
            ->orderByRaw('end_date IS NULL')
            ->orderBy('end_date')
            ->latest('id')
            ->get();

        $progress = $this->progressFor($request, $challenges->pluck('id'));

        return response()->json([
            'success' => true,
            'data' => $challenges->map(fn (Challenge $challenge) => $this->present($challenge, $progress->get($challenge->id))),
        ]);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $challenge = Challenge::with('taxon.apiReferences')->find($id);

        if (! $challenge) {
            return response()->json(['success' => false, 'message' => 'Reto no encontrado'], 404);
        }

        $user = $request->user('sanctum');
        $progress = $this->progressFor($request, collect([$challenge->id]))->get($challenge->id);

        $data = $this->present($challenge, $progress);
        $data['my_observations'] = $user
            ? Observation::with('photos')
                ->where('challenge_id', $challenge->id)
                ->where('user_id', $user->id)
                ->latest('observed_at')
                ->get(['id', 'observed_at', 'location_name', 'verification_status', 'review_note'])
            : [];

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * @return Collection<int, ChallengeParticipation> indexado por challenge_id
     */
    private function progressFor(Request $request, Collection $challengeIds): Collection
    {
        $user = $request->user('sanctum');

        if (! $user || $challengeIds->isEmpty()) {
            return collect();
        }

        return ChallengeParticipation::where('user_id', $user->id)
            ->whereIn('challenge_id', $challengeIds)
            ->get()
            ->keyBy('challenge_id');
    }

    private function present(Challenge $challenge, ?ChallengeParticipation $participation): array
    {
        $taxon = $challenge->taxon?->enriched_data;

        return [
            'id' => $challenge->id,
            'title' => $challenge->title,
            'description' => $challenge->description,
            'reward_points' => (int) $challenge->reward_points,
            'target' => $challenge->requiredObservations(),
            'requires_photo' => (bool) $challenge->requires_photo,
            'start_date' => $challenge->start_date,
            'end_date' => $challenge->end_date,
            'participant_count' => (int) $challenge->participant_count,
            'taxon' => $taxon ? [
                'id' => $taxon['id'],
                'scientific_name' => $taxon['scientific_name'],
                'common_name' => $taxon['common_name'],
                'default_photo' => $taxon['default_photo'] ?? null,
                'is_endemic' => (bool) ($taxon['is_endemic'] ?? false),
                'conservation_status' => $taxon['conservation_status'] ?? null,
            ] : null,
            'my_progress' => $participation ? [
                'progress' => (int) $participation->progress_value,
                'completed' => (bool) $participation->completed,
                'completed_at' => $participation->completed_at,
            ] : null,
        ];
    }
}
