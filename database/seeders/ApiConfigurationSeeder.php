<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ApiConfigurationSeeder extends Seeder
{
    /**
     * Configuraciones iniciales para todas las APIs de biodiversidad
     */
    public function run(): void
    {
        $configurations = [
            [
                'api_source' => 'inaturalist',
                'base_url' => 'https://api.inaturalist.org/v1',
                'api_key_required' => false,
                'rate_limit_requests' => 60,
                'rate_limit_period' => 60, // por minuto
                'daily_limit' => 10000,
                'monthly_limit' => null, // sin límite mensual específico
                'cache_ttl_description' => 604800, // 1 semana
                'cache_ttl_images' => 86400, // 1 día
                'cache_ttl_sounds' => 86400, // 1 día
                'cache_ttl_distribution' => 2592000, // 1 mes
                'cache_ttl_conservation' => 2592000, // 1 mes
                'cache_ttl_characteristics' => 604800, // 1 semana
                'cache_ttl_references' => 2592000, // 1 mes
                'is_active' => true,
                'health_status' => 'healthy',
                'last_health_check' => now()
            ],
            [
                'api_source' => 'gbif',
                'base_url' => 'https://api.gbif.org/v1',
                'api_key_required' => false,
                'rate_limit_requests' => 100,
                'rate_limit_period' => 60,
                'daily_limit' => 100000,
                'monthly_limit' => null,
                'cache_ttl_description' => 604800,
                'cache_ttl_images' => 172800, // 2 días (GBIF tiene muchas imágenes)
                'cache_ttl_sounds' => 86400,
                'cache_ttl_distribution' => 1209600, // 2 semanas (datos de distribución muy estables)
                'cache_ttl_conservation' => 2592000,
                'cache_ttl_characteristics' => 604800,
                'cache_ttl_references' => 2592000,
                'is_active' => true,
                'health_status' => 'healthy',
                'last_health_check' => now()
            ],
            // [
            //     'api_source' => 'eol',
            //     'base_url' => 'https://eol.org/api',
            //     'api_key_required' => true, // EOL requiere token
            //     'rate_limit_requests' => 50,
            //     'rate_limit_period' => 60,
            //     'daily_limit' => 5000,
            //     'monthly_limit' => 100000,
            //     'cache_ttl_description' => 1209600, // 2 semanas (contenido muy estable)
            //     'cache_ttl_images' => 86400,
            //     'cache_ttl_sounds' => 86400,
            //     'cache_ttl_distribution' => 2592000,
            //     'cache_ttl_conservation' => 2592000,
            //     'cache_ttl_characteristics' => 1209600,
            //     'cache_ttl_references' => 2592000,
            //     'is_active' => true,
            //     'health_status' => 'healthy',
            //     'last_health_check' => now()
            // ],
            [
                'api_source' => 'iucn', // IUCN Red List
                'base_url' => 'https://apiv3.iucnredlist.org/api/v3',
                'api_key_required' => true,
                'rate_limit_requests' => 10,
                'rate_limit_period' => 60,
                'daily_limit' => 1000,
                'monthly_limit' => 10000,
                'cache_ttl_description' => 2592000, // 1 mes (datos muy estables)
                'cache_ttl_images' => 86400,
                'cache_ttl_sounds' => 86400,
                'cache_ttl_distribution' => 7776000, // 3 meses (muy estable)
                'cache_ttl_conservation' => 7776000, // 3 meses (muy estable)
                'cache_ttl_characteristics' => 2592000,
                'cache_ttl_references' => 5184000, // 2 meses
                'is_active' => true,
                'health_status' => 'healthy',
                'last_health_check' => now()
            ],
            // [
            //     'api_source' => 'xeno_canto', // Solo para aves
            //     'base_url' => 'https://xeno-canto.org/api/2',
            //     'api_key_required' => false,
            //     'rate_limit_requests' => 30,
            //     'rate_limit_period' => 60,
            //     'daily_limit' => 1000,
            //     'monthly_limit' => null,
            //     'cache_ttl_description' => 2592000,
            //     'cache_ttl_images' => 86400,
            //     'cache_ttl_sounds' => 172800, // 2 días (principal función)
            //     'cache_ttl_distribution' => 2592000,
            //     'cache_ttl_conservation' => 2592000,
            //     'cache_ttl_characteristics' => 604800,
            //     'cache_ttl_references' => 2592000,
            //     'is_active' => true,
            //     'health_status' => 'healthy',
            //     'last_health_check' => now()
            // ],
            // [
            //     'api_source' => 'fishbase', // Para peces
            //     'base_url' => 'https://fishbase.ropensci.org',
            //     'api_key_required' => false,
            //     'rate_limit_requests' => 30,
            //     'rate_limit_period' => 60,
            //     'daily_limit' => 1000,
            //     'monthly_limit' => null,
            //     'cache_ttl_description' => 1209600, // 2 semanas
            //     'cache_ttl_images' => 86400,
            //     'cache_ttl_sounds' => 86400,
            //     'cache_ttl_distribution' => 2592000,
            //     'cache_ttl_conservation' => 2592000,
            //     'cache_ttl_characteristics' => 1209600,
            //     'cache_ttl_references' => 2592000,
            //     'is_active' => true,
            //     'health_status' => 'healthy',
            //     'last_health_check' => now()
            // ],
            // [
            //     'api_source' => 'tropicos', // Para plantas (Missouri Botanical Garden)
            //     'base_url' => 'https://services.tropicos.org/V1.0',
            //     'api_key_required' => true,
            //     'rate_limit_requests' => 100,
            //     'rate_limit_period' => 60,
            //     'daily_limit' => 5000,
            //     'monthly_limit' => null,
            //     'cache_ttl_description' => 1209600,
            //     'cache_ttl_images' => 86400,
            //     'cache_ttl_sounds' => 86400,
            //     'cache_ttl_distribution' => 2592000,
            //     'cache_ttl_conservation' => 2592000,
            //     'cache_ttl_characteristics' => 1209600,
            //     'cache_ttl_references' => 2592000,
            //     'is_active' => false, // Inicialmente desactivada hasta obtener API key
            //     'health_status' => 'unavailable',
            //     'last_health_check' => now()
            // ],
            // [
            //     'api_source' => 'worms', // World Register of Marine Species
            //     'base_url' => 'https://www.marinespecies.org/rest',
            //     'api_key_required' => false,
            //     'rate_limit_requests' => 50,
            //     'rate_limit_period' => 60,
            //     'daily_limit' => 1000,
            //     'monthly_limit' => null,
            //     'cache_ttl_description' => 1209600,
            //     'cache_ttl_images' => 86400,
            //     'cache_ttl_sounds' => 86400,
            //     'cache_ttl_distribution' => 2592000,
            //     'cache_ttl_conservation' => 2592000,
            //     'cache_ttl_characteristics' => 1209600,
            //     'cache_ttl_references' => 2592000,
            //     'is_active' => true,
            //     'health_status' => 'healthy',
            //     'last_health_check' => now()
            // ]
        ];

        foreach ($configurations as $config) {
            DB::table('api_configurations')->insertOrIgnore($config);
        }

        $this->command->info('✅ Configuraciones de APIs insertadas correctamente');
    }
}