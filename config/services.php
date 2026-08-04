<?php

return [

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
        'client_id' => env('B24_CLIENT_ID'),
        'client_secret' => env('B24_CLIENT_SECRET'),
        'redirect_uri' => env('B24_REDIRECT_URI'),
        'portal_domain' => env('B24_PORTAL_DOMAIN'),
        'portal_timezone' => env('B24_PORTAL_TIMEZONE', 'Europe/Moscow'),
        'pause_dry_run' => (bool) env('B24_PAUSE_DRY_RUN', false),
        'entity_type_id' => env('BITRIX_ENTITY_TYPE_ID', env('BITRIX_UNITS_ENTITY_TYPE_ID', 167)),
        'webhook_token' => env('BITRIX_WEBHOOK_TOKEN', env('BITRIX_CONTACTS_EVENT_TOKEN')),
        'open_lines_application_token' => env('BITRIX_OPEN_LINES_APPLICATION_TOKEN'),
        'webhook' => env('BITRIX_WEBHOOK', env('BITRIX_UNITS_WEBHOOK')),
        'disk_webhook' => env('BITRIX_DISK_WEBHOOK', env('BITRIX_WEBHOOK', env('BITRIX_UNITS_WEBHOOK'))),
        'lists' => [
            'utilities_iblock_id' => (int) env('BITRIX_UTILITIES_IBLOCK_ID', 156),
            'disk_iblock_id' => (int) env('BITRIX_DISK_IBLOCK_ID', 322),
        ],
    ],

    'b24_hk' => [
        'portal_domain' => env('B24_HK_PORTAL_DOMAIN'),
        'client_id' => env('B24_HK_CLIENT_ID'),
        'client_secret' => env('B24_HK_CLIENT_SECRET'),
        'redirect_uri' => env('B24_HK_REDIRECT_URI', env('B24_REDIRECT_URI')),
    ],

    'bitrix_im' => [
        'webhook' => env('BITRIX_IM_WEBHOOK'),
        'dialog_id' => env('BITRIX_IM_DIALOG_ID', 'chat561708'),
    ],

    'bitrix_contacts' => [
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

    'chatapp' => [
        'email' => env('CHATAPP_EMAIL'),
        'password' => env('CHATAPP_PASSWORD'),
        'app_id' => env('CHATAPP_APP_ID'),
        'api_url' => env('CHATAPP_API_URL', 'https://api.chatapp.online'),
        'cabinet_line_url' => env(
            'CHATAPP_CABINET_LINE_URL',
            'https://cabinet.chatapp.online/businesses/v2/business-page/57834?tabId=pockets'
        ),
        'alert_threshold' => (int) env('CHATAPP_ALERT_THRESHOLD', 1000),
    ],

];
