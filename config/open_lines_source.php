<?php

return [
    'portal_domain' => env('B24_HK_PORTAL_DOMAIN', 'colifepacific.bitrix24.com'),
    'category_id' => (int) env('OPEN_LINES_SOURCE_CATEGORY_ID', 0),
    'allowed_stage_semantics' => ['P'],
    'uf_flag' => env('OPEN_LINES_SOURCE_UF_FLAG', 'UF_CRM_1792743514293'),
    'uf_flag_value' => env('OPEN_LINES_SOURCE_UF_FLAG_VALUE', 'Y'),
    'handler_url' => env(
        'BITRIX_OPEN_LINES_HANDLER_URL',
        rtrim((string) env('APP_URL', ''), '/').'/api/webhooks/bitrix/open-lines'
    ),
    'events' => [
        'ONOPENLINEMESSAGEADD',
    ],
    'rules' => [
        [
            'name' => '28hse',
            'source_id' => 'UC_JNWVQW',
            'patterns' => [
                'property listing from 28hse',
                'i have just seen your property listing from 28hse',
                '我在 28hse 見到',
                '我在 28hse 见到',
                '28hse.com/rent',
                '28hse.com/en/rent',
            ],
        ],
        [
            'name' => 'FB Groups',
            'source_id' => 'UC_P3614V',
            'patterns' => [
                'facebook post',
                'saw your facebook',
            ],
        ],
        [
            'name' => 'Website organic',
            'source_id' => 'WEB',
            'patterns' => [
                'found your website',
                'your website and would like',
            ],
        ],
        [
            'name' => 'Telegram groups',
            'source_id' => 'UC_TELEGRAM_GRP',
            'patterns' => [
                'promo code: telegram',
                'promo code telegram',
                'telegram group',
                'promo code TELEGRAM',
            ],
        ],
        [
            'name' => 'LinkedIn',
            'source_id' => '13',
            'patterns' => [
                'saw your rooms on Instagram',
            ],
        ],
        [
            'name' => 'WA - NovDec',
            'source_id' => 'UC_TJK8ZS',
            'patterns' => [
                'book a room in advance',
            ],
        ],
    ],
];
