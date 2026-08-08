<?php

namespace Tests\Unit;

use App\Services\ConservationStatusResolver;
use PHPUnit\Framework\TestCase;

class ConservationStatusResolverTest extends TestCase
{
    private ConservationStatusResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ConservationStatusResolver();
    }

    public function test_it_normalizes_the_current_single_object_response(): void
    {
        $result = $this->resolver->resolve([
            'status' => 'vu',
            'status_name' => 'vulnerable',
            'iucn' => 30,
            'authority' => 'IUCN Red List',
            'place_id' => null,
        ]);

        $this->assertSame('VU', $result['code']);
        $this->assertSame('global', $result['scope']);
        $this->assertSame('IUCN Red List', $result['authority']);
    }

    public function test_it_prioritizes_colombia_in_a_list_of_territorial_statuses(): void
    {
        $result = $this->resolver->resolve([
            ['status' => 'lc', 'place_id' => 1, 'authority' => 'Other country'],
            ['status' => 'en', 'place_id' => 7196, 'authority' => 'Lista nacional'],
            ['status' => 'vu', 'place_id' => null, 'authority' => 'IUCN Red List'],
        ]);

        $this->assertSame('EN', $result['code']);
        $this->assertSame('colombia', $result['scope']);
        $this->assertSame(7196, $result['place_id']);
    }

    public function test_it_falls_back_to_global_but_never_to_another_territory(): void
    {
        $global = $this->resolver->resolve([
            ['status' => 'lc', 'place_id' => 1],
            ['status' => 'cr', 'place_id' => null],
        ]);
        $foreignOnly = $this->resolver->resolve([
            ['status' => 'en', 'place_id' => 1],
        ]);

        $this->assertSame('CR', $global['code']);
        $this->assertNull($foreignOnly['code']);
    }

    public function test_missing_information_is_not_mislabeled_as_not_evaluated(): void
    {
        $this->assertNull($this->resolver->resolve(null)['code']);
        $this->assertNull($this->resolver->resolve([])['code']);
    }

    public function test_it_can_use_the_iucn_numeric_equivalent_as_a_fallback(): void
    {
        $result = $this->resolver->resolve(['status' => 'unknown', 'iucn' => 40]);

        $this->assertSame('EN', $result['code']);
    }
}
