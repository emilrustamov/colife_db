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
        'pause_datetime_timezone' => env('B24_PAUSE_DATETIME_TIMEZONE', 'Asia/Tashkent'),
        'pause_dry_run' => (bool) env('B24_PAUSE_DRY_RUN', false),
        'entity_type_id' => env('BITRIX_ENTITY_TYPE_ID'),
        'webhook_token' => env('BITRIX_WEBHOOK_TOKEN'),
        'application_token' => env('B24_APPLICATION_TOKEN'),
        'open_lines_application_token' => env('BITRIX_OPEN_LINES_APPLICATION_TOKEN'),
        'webhook' => env('BITRIX_WEBHOOK'),
        'disk_webhook' => env('BITRIX_DISK_WEBHOOK'),
        'bp_webhook' => env('BITRIX_BP_WEBHOOK'),
        'lists' => [
            'utilities_iblock_id' => (int) env('BITRIX_UTILITIES_IBLOCK_ID', 156),
            'disk_iblock_id' => (int) env('BITRIX_DISK_IBLOCK_ID', 322),
        ],
        'bp_list_template_id' => (int) env('BITRIX_BP_LIST_TEMPLATE_ID', 0),
    ],

    'b24_hk' => [
        'portal_domain' => env('B24_HK_PORTAL_DOMAIN'),
        'client_id' => env('B24_HK_CLIENT_ID'),
        'client_secret' => env('B24_HK_CLIENT_SECRET'),
        'redirect_uri' => env('B24_HK_REDIRECT_URI'),
        'application_token' => env('B24_HK_APPLICATION_TOKEN'),
        'pause_datetime_timezone' => env('B24_HK_PAUSE_DATETIME_TIMEZONE', 'Asia/Tashkent'),
    ],

    'b24_tr' => [
        'portal_domain' => env('B24_TR_PORTAL_DOMAIN'),
        'client_id' => env('B24_TR_CLIENT_ID'),
        'client_secret' => env('B24_TR_CLIENT_SECRET'),
        'redirect_uri' => env('B24_TR_REDIRECT_URI'),
        'application_token' => env('B24_TR_APPLICATION_TOKEN'),
    ],

    'cleaning' => [
        'application_token' => env('CLEANING_APPLICATION_TOKEN'),
        'system_user_id' => (int) env('CLEANING_SYSTEM_USER_ID', 0),
        'assignee_id' => (int) env('CLEANING_ASSIGNEE_ID', 0),
        'participant_id' => (int) env('CLEANING_PARTICIPANT_ID', 0),
        'observer_ids' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('CLEANING_OBSERVER_IDS', ''))
        ))),
        'cleaning_group_id' => (int) env('CLEANING_GROUP_ID', 0),
        'housemaster_group_id' => (int) env('CLEANING_HOUSEMASTER_GROUP_ID', 0),
        'deadline_days' => (int) env('CLEANING_DEADLINE_DAYS', 2),
        'apartment_entity_type_id' => (int) env('CLEANING_APARTMENT_ENTITY_TYPE_ID', 0),
        'unit_entity_type_id' => (int) env('CLEANING_UNIT_ENTITY_TYPE_ID', 0),
        'apartment_units_field' => env('CLEANING_APARTMENT_UNITS_FIELD'),
        'disk_folder_id' => (int) env('CLEANING_DISK_FOLDER_ID', 0),
        'staff' => [
            'Nursel',
            'Lena',
            'Fatma',
            'Oksana',
            'İlvira',
        ],
    ],

    'bitrix_im' => [
        'webhook' => env('BITRIX_IM_WEBHOOK'),
        'dialog_id' => env('BITRIX_IM_DIALOG_ID'),
    ],

    'service_alert_tasks' => [
        'enabled' => (bool) env('SERVICE_ALERT_TASKS_ENABLED', true),
        'webhook' => env('BITRIX_TASK_WEBHOOK'),
        'sample_task_id' => (int) env('SERVICE_ALERT_TASK_SAMPLE_ID', 0),
        'group_id' => (int) env('SERVICE_ALERT_TASK_GROUP_ID', 0),
        'responsible_id' => (int) env('SERVICE_ALERT_TASK_RESPONSIBLE_ID', 0),
        'created_by' => (int) env('SERVICE_ALERT_TASK_CREATED_BY', 0),
        'auditor_ids' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('SERVICE_ALERT_TASK_AUDITOR_IDS', ''))
        ))),
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

    'bitrix_landlord_contacts' => [
        'field_active' => env('BITRIX_LANDLORD_FIELD_ACTIVE', 'UF_CRM_1785851164'),
        'field_ex' => env('BITRIX_LANDLORD_FIELD_EX', 'UF_CRM_1786783496'),
        'type_actual' => env('BITRIX_LANDLORD_TYPE_ACTUAL', 'UC_7F3HSV'),
        'type_ex' => env('BITRIX_LANDLORD_TYPE_EX', 'UC_AHAW7N'),
        'batch_size' => (int) env('BITRIX_LANDLORD_BATCH_SIZE', 50),
    ],

    'client_balance' => [
        'api_key' => env('CLIENT_BALANCE_API_KEY'),
    ],

    'alma' => [
        'api_url' => env('ALMA_API_URL', 'https://colife.argo.properties:1337/external_api/'),
        'api_key' => env('ALMA_API_KEY'),
        'timeout' => (int) env('ALMA_API_TIMEOUT', 120),
    ],

    'chatapp' => [
        'api_url' => env('CHATAPP_API_URL', 'https://api.chatapp.online'),
        'alert_threshold' => (int) env('CHATAPP_ALERT_THRESHOLD', 1000),
        'alert_thresholds' => [
            '76787' => 3000,
            '47838' => 3000,
            '66653' => 3000,
            '73495' => 3000,
            '63158' => 3000,
        ],
        'alert_daily_limits' => [
            '76787' => 4,
        ],
        'pay_method' => 'Счёт',
        'accounts' => [
            'ae' => [
                'email' => env('CHATAPP_EMAIL'),
                'password' => env('CHATAPP_PASSWORD'),
                'app_id' => env('CHATAPP_APP_ID'),
                'cabinet_line_url' => env(
                    'CHATAPP_CABINET_LINE_URL',
                    'https://cabinet.chatapp.online/businesses/v2/business-page/57834?tabId=pockets'
                ),
                'flag' => '🇦🇪',
            ],
            'hk' => [
                'email' => env('CHATAPP_HK_EMAIL'),
                'password' => env('CHATAPP_HK_PASSWORD'),
                'app_id' => env('CHATAPP_HK_APP_ID'),
                'cabinet_line_url' => env('CHATAPP_HK_CABINET_LINE_URL', ''),
                'flag' => '🇭🇰',
            ],
        ],
    ],

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'alert_threshold' => (float) env('TWILIO_ALERT_THRESHOLD', 50),
        'portal' => env('TWILIO_PORTAL', 'AE'),
    ],

    'vdsina' => [
        'api_url' => env('VDSINA_API_URL', 'https://userapi.vdsina.com/v1'),
        'api_token' => env('VDSINA_API_TOKEN'),
        'alert_threshold' => (float) env('VDSINA_ALERT_THRESHOLD', 50),
        'cabinet_url' => env('VDSINA_CABINET_URL', 'https://cp.vdsina.com/vds/list'),
        'portal' => env('VDSINA_PORTAL', 'AE'),
        'pay_method' => 'Карта',
    ],

    'wazzup' => [
        'api_url' => env('WAZZUP_API_URL', 'https://api.wazzup24.com/v3'),
        'api_key' => env('WAZZUP_API_KEY'),
        'portal' => env('WAZZUP_PORTAL', 'HK'),
        'cabinet_url' => env('WAZZUP_CABINET_URL', 'https://app.wazzup24.com/'),
        'pay_method' => env('WAZZUP_PAY_METHOD', 'Карта'),
        'alert_states' => array_values(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', (string) env('WAZZUP_ALERT_STATES', 'notEnoughMoney,disabled'))
        ))),
    ],

    'service_payments' => [
        'entity_type_id' => 1094,
        'category_id' => 74,
        'inactive_stage' => 'DT1094_74:FAIL',
        'services' => [
            10828 => 'ChatApp',
            10830 => 'CallGear',
            11368 => 'Miro',
            // 11370 => 'Wikibot',
        ],
        'api_owned_services' => [
            10832 => 'Twilio',
            11352 => 'VDSina',
        ],
        'portals' => [
            10834 => 'AE',
            10836 => 'AE',
            10838 => 'HK',
            10840 => 'TR',
        ],
        'pay_methods' => [
            16964 => 'Карта',
            16966 => 'Счёт',
        ],
        'default_pay_methods' => [
            10828 => 'Счёт',
        ],
        'cabinet_urls' => [
            10828 => [
                'AE' => env(
                    'CHATAPP_CABINET_LINE_URL',
                    'https://cabinet.chatapp.online/businesses/v2/business-page/57834?tabId=pockets'
                ),
                'HK' => env('CHATAPP_HK_CABINET_LINE_URL', ''),
            ],
            10830 => env('CALLGEAR_CABINET_URL', ''),
            11368 => env('MIRO_CABINET_URL', 'https://miro.com/app/dashboard/'),
            // 11370 => env('WIKIBOT_CABINET_URL', ''),
        ],
    ],

    'telegram' => [
        'enabled' => filter_var(env('TELEGRAM_ALERTS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'disk_lookback_hours' => (int) env('TELEGRAM_DISK_LOOKBACK_HOURS', 6),
        'pause_failed_lookback_hours' => (int) env('TELEGRAM_PAUSE_FAILED_LOOKBACK_HOURS', 6),
        'dedupe_ttl' => (int) env('TELEGRAM_ALERT_DEDUPE_TTL', 86400),
        'instant_dedupe_ttl' => (int) env('TELEGRAM_INSTANT_DEDUPE_TTL', 3600),
    ],

];
