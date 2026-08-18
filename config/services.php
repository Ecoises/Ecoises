<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],
    'inaturalist' => [
        'base_url' => env('INATURALIST_BASE_URL', 'https://api.inaturalist.org/v1'),
        'api_key' => env('INATURALIST_API_KEY'),
        'timeout' => (int) env('INATURALIST_TIMEOUT', 30),
        'rate_limit' => (int) env('INATURALIST_RATE_LIMIT', 100),
        'per_page' => (int) env('INATURALIST_PER_PAGE', 30),
        'place_id' => (int) env('INATURALIST_PLACE_ID', 7196), // Colombia
        'preferred_place_id' => (int) env('INATURALIST_PREFERRED_PLACE_ID', 7196), // Colombia
    ],

    'eol' => [
        'base_url' => env('EOL_BASE_URL', 'https://eol.org/api'),
        'timeout' => (int) env('EOL_TIMEOUT', 30),
        'cache_ttl' => (int) env('EOL_CACHE_TTL', 43200),
    ],

    'wikipedia' => [
        'base_url' => env('WIKIPEDIA_BASE_URL', 'https://es.wikipedia.org/api/rest_v1'),
        'timeout' => (int) env('WIKIPEDIA_TIMEOUT', 15),
    ],
    'google' => [
        'gemini_key' => env('GOOGLE_AI_STUDIO_KEY'),
    ],
    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
    ],

    'explorer' => [
        'cell_size_km' => (int) env('EXPLORER_CELL_SIZE_KM', 50),
        'max_species_per_cell' => (int) env('EXPLORER_MAX_SPECIES_PER_CELL', 500),
        'preload_cities' => env('EXPLORER_PRELOAD_CITIES', true),
    ],

];
