<?php
$names = [
    "Opuntia cochenillifera (L.) Mill.",
    "Thevetia ahouai (L.) A.DC.",
    "Syssphinx molina Cramer, 1781",
    "Ruellia simplex C.Wright",
    "Cordyline fruticosa (L.) A.Chev.",
    "Helosis cayennensis (Sw.) Spreng.",
    "Nerium oleander L.",
    "Caladium bicolor (Aiton) Vent.",
    "Allamanda blanchetii A.DC.",
    "Bomarea multiflora (L.f.) Mirb.",
    "Euphorbia cotinifolia L.",
    "Teloschistes flavicans (Sw.) Norman",
    "Phytolacca bogotensis Kunth",
    "Calotropis procera (Aiton) W.T.Aiton",
    "Conirostrum sitticolor Lafresnaye, 1840",
    "Ancognatha scarabaeoides Erichson, 1847",
    "Jatropha gossypiifolia L.",
    "Etlingera elatior (Jack) R.M.Sm.",
    "Corydalus armatus Hagen, 1861",
    "Coffea arabica L.",
    "Coleus scutellarioides (L.) Benth.",
    "Theobroma cacao L.",
    "Ixora coccinea Comm. ex Lam.",
    "Golofa porteri Hope, 1837",
    "Persea americana Mill.",
    "Fragaria × ananassa Duchesne",
    "Rhetus arcius (Linnaeus, 1763)",
    "Boa constrictor constrictor Linnaeus, 1758",
    "Passer domesticus domesticus",
    "Cattleya trianae var. semialba",
    "Solanum lycopersicum"
];

function testStripAuthorship(string $name): string
{
    // Match:
    // 1. Genus (e.g. "Solanum" or "Fragaria")
    // 2. Optional hybrid sign (e.g. "× " or "x ")
    // 3. Species epithet (e.g. "lycopersicum")
    // 4. Optional rank word + infraspecific epithet OR just infraspecific epithet
    // Note: We use /u for unicode hybrid signs.
    $pattern = '/^([A-Z][a-z\-]+)\s+(?:[×x]\s+)?([a-z\-]+)(?:\s+(?:subsp\.|var\.|f\.|subspecies)\s+([a-z\-]+)|(?:\s+([a-z\-]+)))?/iu';
    if (preg_match($pattern, $name, $matches)) {
        // If we matched the 4th group (infraspecific epithet without rank word), 
        // we check if it is capitalized (which might be authorship, not an epithet).
        // For example, "Nerium oleander L." -> "L." is capitalized, so it is NOT an epithet.
        // "Syssphinx molina Cramer" -> "Cramer" is capitalized, so it is NOT an epithet.
        // "Boa constrictor constrictor" -> "constrictor" is lowercase, so it IS an epithet.
        // Let's implement this logic.
        $genus = $matches[1];
        $hybrid = str_contains($matches[0], ' × ') ? ' × ' : (str_contains($matches[0], ' x ') ? ' x ' : ' ');
        $species = $matches[2];
        
        $clean = $genus . $hybrid . $species;
        
        // Check if we matched group 3 (infraspecific with rank word)
        if (!empty($matches[3])) {
            // Re-find the exact rank word used
            if (preg_match('/\s+(subsp\.|var\.|f\.|subspecies)\s+/i', $name, $rankMatches)) {
                $clean .= ' ' . $rankMatches[1] . ' ' . $matches[3];
            }
        }
        // Check if we matched group 4 (infraspecific without rank word)
        elseif (!empty($matches[4])) {
            $infra = $matches[4];
            // If it is lowercase, we keep it as a zoological trinomial
            if (ctype_lower(str_replace('-', '', $infra))) {
                $clean .= ' ' . $infra;
            }
        }
        return $clean;
    }
    return $name;
}

foreach ($names as $n) {
    echo sprintf("%-45s => %s\n", $n, testStripAuthorship($n));
}
