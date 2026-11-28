<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        'http://localhost:8443',
        'http://127.0.0.1:8443',
        // Set FRONTEND_URL to the single production frontend origin.
        env('FRONTEND_URL'),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Content-Disposition',
        'X-Pagination-Current-Page',
        'X-Pagination-Last-Page',
        'X-Pagination-Per-Page',
        'X-Pagination-Total',
    ],

    'max_age' => 3600,

    'supports_credentials' => false,

];