<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\EnrichSpeciesEcologyJob;
use App\Jobs\GenerateEcologyTextJob;
use App\Jobs\SyncSpeciesInteractionsJob;
use App\Models\Taxa;
use App\Services\Biodiversity\ConservationLabelService;
use App\Services\Biodiversity\EcologicalImportanceService;
use App\Services\Biodiversity\EcologyTextService;
use App\Services\Biodiversity\TaxonCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fichas de especies del catálogo local.
 * El catálogo geográfico (GBIF) vive en ExplorerController.
 */
class TaxonController extends Controller
{
    public function __construct(
        private readonly TaxonCatalogService $catalog,
        private readonly EcologicalImportanceService $ecology,
        private readonly EcologyTextService $ecologyTexts,
        private readonly ConservationLabelService $labels,
    ) {}

    /**
     * GET /taxa/search?q= — buscador por nombre (lo usa el formulario de avistamientos).
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:255',
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);

        $result = $this->catalog->search($validated['q'], (int) ($validated['per_page'] ?? 15));

        return response()->json([
            'success' => true,
            'message' => 'Búsqueda completada correctamente',
            'data' => $result['data'],
            'meta' => ['source' => 'local', 'pagination' => $result['pagination']],
        ]);
    }

    /**
     * GET /taxa/{id} — ficha completa de una especie.
     */
    public function show(int $id, Request $request): JsonResponse
    {
        ['taxon' => $taxon, 'source' => $source] = $this->catalog->find($id, $request->boolean('refresh'));

        if (! $taxon) {
            return response()->json([
                'success' => false,
                'message' => 'Especie no encontrada',
                'data' => null,
            ], 404);
        }

        $this->queueEcologyIfStale($taxon);
        $this->catalog->ensureGbifTaxonomy($taxon);

        // Categoría UICN (vía GBIF): se consulta una vez por especie y queda guardada.
        if ($taxon->gbif_taxon_key && ! $taxon->iucn_synced_at) {
            $this->labels->syncIucn(collect([$taxon]));
        }

        $data = $request->boolean('enrich', true) ? $taxon->enriched_data : $taxon->toArray();
        $data['ecological_importance'] = $this->ecology->present($taxon);
        $data['ecology_text'] = $this->presentEcologyText($taxon);
        // Etiquetas oficiales: amenaza en Colombia, UICN global y origen, cada una con su fuente.
        $data['labels'] = $this->labels->labelsFor($taxon);
        $data['gbif_taxon_key'] = $taxon->gbif_taxon_key ? (string) $taxon->gbif_taxon_key : null;
        $data['taxonomy_source'] = $taxon->gbif_taxon_key ? 'GBIF Backbone Taxonomy' : 'iNaturalist';

        return response()->json([
            'success' => true,
            'message' => 'Taxón obtenido correctamente',
            'data' => $data,
            'meta' => ['source' => $source],
        ]);
    }

    /**
     * GET /taxa/{id}/observations — avistamientos públicos de la comunidad.
     */
    public function observations(int $id, Request $request): JsonResponse
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100']);

        if (! Taxa::whereKey($id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Taxón no encontrado', 'data' => null], 404);
        }

        $result = $this->catalog->observations($id, $request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Observaciones obtenidas correctamente',
            'data' => $result['data'],
            'meta' => ['source' => 'local', 'pagination' => $result['pagination']],
        ]);
    }

    /**
     * GET /taxa/{id}/related — especies parecidas (mismo género o familia).
     */
    public function related(int $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->catalog->related($id),
        ]);
    }

    /**
     * La ecología (EOL) y las interacciones (GloBI) se completan en segundo
     * plano: esas APIs pueden tardar varios segundos y no deben bloquear la ficha.
     */
    private function queueEcologyIfStale(Taxa $taxon): void
    {
        $eol = $taxon->apiReferences->firstWhere('api_source', 'eol');

        if (! $eol?->last_verified_at || $eol->last_verified_at->lt(now()->subMonths(6))) {
            EnrichSpeciesEcologyJob::dispatch($taxon->id);
        }

        if ($this->ecology->needsSync($taxon)) {
            SyncSpeciesInteractionsJob::dispatch($taxon->id);
        }

        // Primera visita a una especie sin texto: se redacta una vez, en segundo plano.
        if ($this->ecologyTexts->needsText($taxon)) {
            GenerateEcologyTextJob::dispatch($taxon->id)->delay(now()->addSeconds(20));
        }
    }

    private function presentEcologyText(Taxa $taxon): ?array
    {
        $text = $this->ecologyTexts->textFor($taxon);

        return $text ? [
            'text' => $text->text,
            'sources' => $text->sources ?? [],
            'reviewed' => $text->status === \App\Models\SpeciesEcologyText::STATUS_REVIEWED,
            'generated_at' => $text->generated_at,
        ] : null;
    }
}
