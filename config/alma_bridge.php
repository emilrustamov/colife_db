<?php

return [
    'enabled' => filter_var(env('ALMA_BRIDGE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'default_project' => env('PROJECT_DEFAULT_NAME', 'Dubai'),
    'web_url' => env('ALMA_WEB_URL', 'https://colife.alma-app.com'),
    'events' => [
        'deal_add' => env('BITRIX_EVENT_DEAL_ADD', 'ONCRMDEALADD'),
        'deal_update' => env('BITRIX_EVENT_DEAL_UPDATE', 'ONCRMDEALUPDATE'),
        'dynamic_update' => env('BITRIX_EVENT_DYNAMIC_UPDATE', 'ONCRMDYNAMICITEMUPDATE'),
        'contact_update' => env('BITRIX_EVENT_CONTACT_UPDATE', 'ONCRMCONTACTUPDATE'),
    ],
    'projects' => [
        'Dubai' => [
            'id' => (int) env('PROJECT_DUBAI_ID', 1),
            'name' => env('PROJECT_DUBAI_NAME', 'Dubai'),
            'domain' => env('PROJECT_DUBAI_DOMAIN'),
            'webhook_url' => env('PROJECT_DUBAI_WEBHOOK_URL'),
            'webhook_outgoing_url' => env('PROJECT_DUBAI_WEBHOOK_OUTGOING_URL'),
            'webhook_token' => env('PROJECT_DUBAI_WEBHOOK_TOKEN'),
        ],
        'HongKong' => [
            'id' => (int) env('PROJECT_HONGKONG_ID', 2),
            'name' => env('PROJECT_HONGKONG_NAME', 'HongKong'),
            'domain' => env('PROJECT_HONGKONG_DOMAIN'),
            'webhook_url' => env('PROJECT_HONGKONG_WEBHOOK_URL'),
            'webhook_outgoing_url' => env('PROJECT_HONGKONG_WEBHOOK_OUTGOING_URL'),
            'webhook_token' => env('PROJECT_HONGKONG_WEBHOOK_TOKEN'),
        ],
    ],
    'mappings' => require __DIR__.'/alma_bridge_mappings.php',
];
