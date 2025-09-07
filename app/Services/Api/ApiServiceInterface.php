<?php

namespace App\Services\Api;

interface ApiServiceInterface
{
    /**
     * Obtiene información de un taxón por su ID en la API externa
     *
     * @param string $id
     * @return array
     */
    public function getTaxonById(string $id): array;

    /**
     * Busca taxones por nombre científico
     *
     * @param string $scientificName
     * @return array
     */
    public function searchTaxon(string $scientificName): array;
    
    /**
     * Obtiene las observaciones de un taxón específico
     *
     * @param string $taxonId
     * @param array $params
     * @return array
     */
    public function getTaxonObservations(string $taxonId, array $params = []): array;
    
    /**
     * Obtiene información de una ubicación específica
     *
     * @param string $locationId
     * @return array
     */
    public function getLocationInfo(string $locationId): array;
    
    /**
     * Obtiene la información de la API (nombre, límites, etc.)
     *
     * @return array
     */
    public function getApiInfo(): array;
}
