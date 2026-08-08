<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Taxa;
use App\Services\SpeciesMerger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$allTaxa = Taxa::all();
$deletedCount = 0;
$renamedCount = 0;
$mergedCount = 0;

echo "Starting database validation and cleanup...\n";

DB::transaction(function() use ($allTaxa, &$deletedCount, &$renamedCount, &$mergedCount) {
    foreach ($allTaxa as $taxon) {
        $cleanName = SpeciesMerger::stripAuthorship($taxon->scientific_name);
        
        if ($taxon->scientific_name !== $cleanName) {
            echo "Processing duplicate/raw taxon ID {$taxon->id}: '{$taxon->scientific_name}' => clean: '{$cleanName}'\n";
            
            $canonicalTaxon = Taxa::where('scientific_name', $cleanName)->first();
            
            if ($canonicalTaxon) {
                echo "  Found existing canonical taxon ID {$canonicalTaxon->id} for '{$cleanName}'. Merging...\n";
                
                // Move observations if table exists
                if (Schema::hasTable('observations')) {
                    $obsCount = DB::table('observations')->where('taxon_id', $taxon->id)->update(['taxon_id' => $canonicalTaxon->id]);
                    if ($obsCount > 0) echo "    Moved {$obsCount} observations.\n";
                }
                
                // Move identifications if table exists
                if (Schema::hasTable('identifications')) {
                    $identCount = DB::table('identifications')->where('taxon_id', $taxon->id)->update(['taxon_id' => $canonicalTaxon->id]);
                    if ($identCount > 0) echo "    Moved {$identCount} identifications.\n";
                }
                
                // Move api references if table exists
                if (Schema::hasTable('taxon_api_references')) {
                    $duplicateRefs = DB::table('taxon_api_references')->where('taxon_id', $taxon->id)->get();
                    foreach ($duplicateRefs as $ref) {
                        $existsOnCanonical = DB::table('taxon_api_references')
                            ->where('taxon_id', $canonicalTaxon->id)
                            ->where('api_source', $ref->api_source)
                            ->exists();
                        if (!$existsOnCanonical) {
                            DB::table('taxon_api_references')->where('id', $ref->id)->update(['taxon_id' => $canonicalTaxon->id]);
                            echo "    Moved {$ref->api_source} api reference.\n";
                        }
                    }
                    DB::table('taxon_api_references')->where('taxon_id', $taxon->id)->delete();
                }
                
                $taxon->delete();
                $deletedCount++;
                $mergedCount++;
            } else {
                echo "  No canonical taxon exists. Renaming ID {$taxon->id} to '{$cleanName}'...\n";
                try {
                    $taxon->update([
                        'scientific_name' => $cleanName
                    ]);
                    $renamedCount++;
                } catch (\Exception $e) {
                    echo "    Failed to rename: " . $e->getMessage() . "\n";
                }
            }
        }
    }
});

echo "\nCleanup summary:\n";
echo "- Merged & Deleted: $deletedCount duplicates\n";
echo "- Renamed to clean: $renamedCount taxa\n";
