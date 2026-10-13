<?php

namespace Tests\Unit;

use App\Services\Biodiversity\SpeciesMerger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScientificNameTest extends TestCase
{
    public static function names(): array
    {
        return [
            ['Quiscalus lugubris Swainson, 1838', 'Quiscalus lugubris', 'Swainson, 1838'],
            ['Rhetus arcius (Linnaeus, 1763)', 'Rhetus arcius', '(Linnaeus, 1763)'],
            ['Axinaea costaricensis Cogn.', 'Axinaea costaricensis', 'Cogn.'],
            ['Passiflora tarminiana Coppens & V.E.Barney', 'Passiflora tarminiana', 'Coppens & V.E.Barney'],
            ['Tangara gyrola toddi Bond, 1950', 'Tangara gyrola toddi', 'Bond, 1950'],
            ['Agave americana var. marginata Trel.', 'Agave americana var. marginata', 'Trel.'],
            ['Psidium guajava de Candolle', 'Psidium guajava', 'de Candolle'],
            ['Quiscalus Vieillot, 1816', 'Quiscalus', 'Vieillot, 1816'],
            ['Zygodontomys sp.', 'Zygodontomys sp.', null],
            ['Tremarctos ornatus', 'Tremarctos ornatus', null],
            ['Aves', 'Aves', null],
            ['Passiflora tarminiana × tripartita', 'Passiflora tarminiana × tripartita', null],
            ['Passiflora × rosea Hort.', 'Passiflora × rosea', 'Hort.'],
            ['  Anolis   auratus  Daudin, 1802 ', 'Anolis auratus', 'Daudin, 1802'],
        ];
    }

    #[DataProvider('names')]
    public function test_it_separates_the_name_from_the_author(string $raw, string $clean, ?string $author): void
    {
        $this->assertSame($clean, SpeciesMerger::stripAuthorship($raw));
        $this->assertSame($author, SpeciesMerger::authorship($raw));
    }
}
