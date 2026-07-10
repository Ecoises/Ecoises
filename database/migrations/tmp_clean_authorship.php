<?php
// Temporary script: clean authorship from existing taxa
$taxa = \App\Models\Taxa::where('scientific_name', 'like', '%)%')
    ->orWhere('scientific_name', 'like', '%,%')
    ->get();

$count = 0;
foreach ($taxa as $t) {
    $clean = \App\Services\SpeciesMerger::stripAuthorship($t->scientific_name);
    if ($clean !== $t->scientific_name) {
        $t->update(['scientific_name' => $clean]);
        echo "Limpie: {$t->scientific_name} -> {$clean}\n";
        $count++;
    }
}
echo "Hecho. {$count} registros limpiados.\n";
