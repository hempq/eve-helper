<?php

return [

    /*
    |--------------------------------------------------------------------------
    | EVE SSO (OAuth2)
    |--------------------------------------------------------------------------
    |
    | Register your application at https://developers.eveonline.com to obtain
    | a client id/secret. The callback URL registered there must match the
    | eve.callback route exactly.
    |
    */

    'sso' => [
        'client_id' => env('EVE_CLIENT_ID'),
        'client_secret' => env('EVE_CLIENT_SECRET'),
        'authorize_url' => 'https://login.eveonline.com/v2/oauth/authorize',
        'token_url' => 'https://login.eveonline.com/v2/oauth/token',
        'jwks_url' => 'https://login.eveonline.com/oauth/jwks',
        // Both issuer spellings occur in the wild; the validator accepts either.
        'issuers' => ['https://login.eveonline.com', 'login.eveonline.com'],

        'scopes' => [
            'esi-skills.read_skills.v1',
            'esi-skills.read_skillqueue.v1',
            'esi-clones.read_clones.v1',
            'esi-clones.read_implants.v1',
            'esi-wallet.read_character_wallet.v1',
            'esi-assets.read_assets.v1',
            'esi-markets.read_character_orders.v1',
            'esi-markets.structure_markets.v1',
            'esi-location.read_location.v1',
            'esi-location.read_online.v1',
            'esi-location.read_ship_type.v1',
            'esi-ui.write_waypoint.v1',
            'esi-search.search_structures.v1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ESI HTTP client
    |--------------------------------------------------------------------------
    |
    | ESI requires a descriptive User-Agent with contact information and, on
    | the current API, an X-Compatibility-Date header instead of versioned
    | URL paths. Bump the pinned date deliberately and re-run the test suite.
    |
    */

    'esi' => [
        'base_url' => env('EVE_ESI_BASE_URL', 'https://esi.evetech.net'),
        'compatibility_date' => env('EVE_ESI_COMPATIBILITY_DATE', '2026-09-01'),
        'user_agent' => env('EVE_ESI_USER_AGENT', 'EveHelper/0.1 (missing-contact)'),
        // Stop calling ESI when fewer than this many errors remain in the
        // rolling error-limit window; exceeding the window blocks the app.
        'error_limit_threshold' => (int) env('EVE_ESI_ERROR_LIMIT_THRESHOLD', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Static Data Export
    |--------------------------------------------------------------------------
    */

    'sde' => [
        'base_url' => env('EVE_SDE_BASE_URL', 'https://www.fuzzwork.co.uk/dump/latest/csv/'),
    ],

];
