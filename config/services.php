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
        'entity_type_id' => env('BITRIX_ENTITY_TYPE_ID', env('BITRIX_UNITS_ENTITY_TYPE_ID', 167)),
        'webhook_token' => env('BITRIX_WEBHOOK_TOKEN', env('BITRIX_CONTACTS_EVENT_TOKEN')),
        'open_lines_application_token' => env('BITRIX_OPEN_LINES_APPLICATION_TOKEN'),
        'webhook' => env('BITRIX_WEBHOOK', env('BITRIX_UNITS_WEBHOOK')),
        'disk_webhook' => env('BITRIX_DISK_WEBHOOK', env('BITRIX_WEBHOOK', env('BITRIX_UNITS_WEBHOOK'))),
        'bp_webhook' => env('BITRIX_BP_WEBHOOK', env('BITRIX_WEBHOOK', env('BITRIX_UNITS_WEBHOOK'))),
        'lists' => [
            'utilities_iblock_id' => (int) env('BITRIX_UTILITIES_IBLOCK_ID', 156),
            'disk_iblock_id' => (int) env('BITRIX_DISK_IBLOCK_ID', 322),
        ],
        'bp_list_template_id' => (int) env('BITRIX_BP_LIST_TEMPLATE_ID', 3666),
    ],

    'b24_hk' => [
        'portal_domain' => env('B24_HK_PORTAL_DOMAIN'),
        'client_id' => env('B24_HK_CLIENT_ID'),
        'client_secret' => env('B24_HK_CLIENT_SECRET'),
        'redirect_uri' => env('B24_HK_REDIRECT_URI', env('B24_REDIRECT_URI')),
        'pause_datetime_timezone' => env('B24_HK_PAUSE_DATETIME_TIMEZONE', env('B24_PAUSE_DATETIME_TIMEZONE', 'Asia/Tashkent')),
    ],

    'b24_tr' => [
        'portal_domain' => env('B24_TR_PORTAL_DOMAIN', 'colife.bitrix24.com.tr'),
        'client_id' => env('B24_TR_CLIENT_ID'),
        'client_secret' => env('B24_TR_CLIENT_SECRET'),
        'redirect_uri' => env('B24_TR_REDIRECT_URI', env('B24_REDIRECT_URI')),
    ],

    'cleaning' => [
        'system_user_id' => (int) env('CLEANING_SYSTEM_USER_ID', 71857),
        'assignee_id' => (int) env('CLEANING_ASSIGNEE_ID', 62997),
        'participant_id' => (int) env('CLEANING_PARTICIPANT_ID', 110103),
        'observer_ids' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('CLEANING_OBSERVER_IDS', env('CLEANING_AUDITOR_IDS', '109833,87311,25681')))
        ))),
        'cleaning_group_id' => (int) env('CLEANING_GROUP_ID', 13),
        'housemaster_group_id' => (int) env('CLEANING_HOUSEMASTER_GROUP_ID', 27),
        'deadline_days' => (int) env('CLEANING_DEADLINE_DAYS', 2),
        'apartment_entity_type_id' => (int) env('CLEANING_APARTMENT_ENTITY_TYPE_ID', 165),
        'unit_entity_type_id' => (int) env('CLEANING_UNIT_ENTITY_TYPE_ID', 144),
        'apartment_units_field' => env('CLEANING_APARTMENT_UNITS_FIELD', 'ufCrm5_1697012153'),
        'disk_folder_id' => (int) env('CLEANING_DISK_FOLDER_ID', 396621),
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

    'chatapp' => [
        'api_url' => env('CHATAPP_API_URL', 'https://api.chatapp.online'),
        'alert_threshold' => (int) env('CHATAPP_ALERT_THRESHOLD', 1000),
        'alert_thresholds' => [
            '76787' => 2000,
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

    'regru' => [
        'api_url' => env('REGRU_API_URL', 'https://api.reg.ru/api/regru2'),
        'username' => env('REGRU_USERNAME'),
        'password' => env('REGRU_PASSWORD'),
        'alert_days' => (int) env('REGRU_ALERT_DAYS', 30),
        'cabinet_url' => env('REGRU_CABINET_URL', 'https://www.reg.ru/user/account/#/domains'),
        'portal' => env('REGRU_PORTAL', 'AE'),
    ],

    'service_payments' => [
        'entity_type_id' => 1094,
        'category_id' => 74,
        'inactive_stage' => 'DT1094_74:FAIL',
        'services' => [
            10828 => 'ChatApp',
            10830 => 'CallGear',
            11366 => 'REG.RU',
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
            11366 => env('REGRU_CABINET_URL', 'https://www.reg.ru/user/account/#/domains'),
            11368 => env('MIRO_CABINET_URL', 'https://miro.com/app/dashboard/'),
            // 11370 => env('WIKIBOT_CABINET_URL', ''),
        ],
    ],

];
