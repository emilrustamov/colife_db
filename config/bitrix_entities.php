<?php

return [
    'portals' => [
        'ae' => [
            'domain' => env('B24_PORTAL_DOMAIN'),
            'entities' => [
                'apartments' => 144,
                'apartment_ownerships' => 148,
                'units' => 167,
                'unit_stays' => 183,
            ],
        ],
        'hk' => [
            'domain' => env('B24_HK_PORTAL_DOMAIN'),
            'entities' => [],
        ],
        'tr' => [
            'domain' => env('B24_TR_PORTAL_DOMAIN'),
            'entities' => [
                'apartments' => 165,
                'units' => 144,
            ],
        ],
    ],
];
