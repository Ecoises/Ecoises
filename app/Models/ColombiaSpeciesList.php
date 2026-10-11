<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de una especie en una lista oficial de Colombia (ver
 * ConservationLabelService::LISTS para las fuentes).
 */
class ColombiaSpeciesList extends Model
{
    public const THREAT = 'threat_co';

    public const ENDEMIC_BIRDS = 'endemic_birds_co';

    public const INTRODUCED = 'introduced_co';

    protected $fillable = ['list', 'gbif_taxon_key', 'scientific_name', 'category'];
}
