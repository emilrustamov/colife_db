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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'bitrix' => [
        'webhook' => env('BITRIX_WEBHOOK', env('BITRIX_UNITS_WEBHOOK')),
        'entity_type_id' => env('BITRIX_ENTITY_TYPE_ID', env('BITRIX_UNITS_ENTITY_TYPE_ID', 167)),
    ],

    'bitrix_contacts' => [
        'timeout' => env('BITRIX_CONTACTS_TIMEOUT', 60),
        'event_token' => env('BITRIX_CONTACTS_EVENT_TOKEN'),
        'push' => [
            'update_method' => 'crm.contact.update.json',
            'field_map' => [
                'first_name' => 'NAME',
                'last_name' => 'LAST_NAME',
                'birth_date' => 'BIRTHDATE',
            ],
        ],
    ],

    'client_balance' => [
        'api_key' => env('CLIENT_BALANCE_API_KEY'),
    ],

];
