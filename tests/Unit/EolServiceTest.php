<?php

namespace Tests\Unit;

use App\Services\Api\EolService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EolServiceTest extends TestCase
{
    #[Test]
    public function it_does_not_present_a_general_ecology_article_as_an_ecological_role(): void
    {
        $service = new class extends EolService
        {
            public function normalizeForTest(array $taxonConcept, int $eolId): array
            {
                return $this->normalizeEcologyProfile($taxonConcept, $eolId);
            }
        };

        $profile = $service->normalizeForTest([
            'scientificName' => 'Species example',
            'dataObjects' => [[
                'dataType' => 'http://purl.org/dc/dcmitype/Text',
                'description' => '<p>Esta especie vive en bosques húmedos de tierras bajas.</p>',
                'subject' => ['Ecology'],
                'language' => 'es',
                'vettedStatus' => 'Trusted',
            ]],
        ], 123);

        $this->assertNull($profile['role']);
        $this->assertNull($profile['highlight']);
        $this->assertSame(2, $profile['schema_version']);
    }

    #[Test]
    public function it_builds_a_spanish_ecological_role_from_attributed_eol_evidence(): void
    {
        $service = new class extends EolService
        {
            public function normalizeForTest(array $taxonConcept, int $eolId): array
            {
                return $this->normalizeEcologyProfile($taxonConcept, $eolId);
            }
        };

        $profile = $service->normalizeForTest([
            'scientificName' => 'Vultur gryphus',
            'dataObjects' => [[
                'dataType' => 'http://purl.org/dc/dcmitype/Text',
                'description' => '<p>This species scavenges on animal carcasses.</p>',
                'subject' => ['Biology'],
                'language' => 'en',
                'license' => 'https://creativecommons.org/licenses/by/4.0/',
                'vettedStatus' => 'Trusted',
                'dataRating' => '4.0',
                'agents' => [['full_name' => 'Scientific provider']],
            ]],
        ], 1049160);

        $this->assertSame('Carroñero', $profile['role']['name']);
        $this->assertSame('es', $profile['role']['language']);
        $this->assertSame('Scientific provider', $profile['role']['provider']);
        $this->assertSame('derived_from_source', $profile['role']['evidence_level']);
        $this->assertSame('https://eol.org/pages/1049160', $profile['eol_url']);
    }
}
