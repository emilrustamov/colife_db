<?php

return [
    'portal_domain' => env('B24_HK_PORTAL_DOMAIN', 'colifepacific.bitrix24.com'),
    'category_id' => (int) env('OPEN_LINES_SOURCE_CATEGORY_ID', 0),
    'allowed_stage_semantics' => ['P'],
    'line_id' => (int) env('OPEN_LINES_SOURCE_LINE_ID', 5),
    'uf_flag' => env('OPEN_LINES_SOURCE_UF_FLAG', 'UF_CRM_1792743514293'),
    'uf_flag_value' => env('OPEN_LINES_SOURCE_UF_FLAG_VALUE', 'Y'),
    'uf_promo' => env('OPEN_LINES_SOURCE_UF_PROMO', 'UF_CRM_1793861655792'),
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
                '我在 28Hse 見到',
                '我在 28hse 见到',
                '28hse.com/rent',
                '28hse.com/en/rent',
                '28hse.com',
                '28hse',
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
            'name' => 'WA - NovDec',
            'source_id' => 'UC_TJK8ZS',
            'patterns' => [
                'book a room in advance',
            ],
        ],
        [
            'name' => 'Common room',
            'source_id' => 'UC_0FLL9P',
            'patterns' => [
                'commonroom平台',
            ],
        ],
        [
            'name' => 'Promo Winter',
            'promo_value' => 4433,
            'patterns' => [
                'i want to rent a flat with a discount. my promo code is winter',
                'my promo code is winter',
            ],
        ],
        [
            'name' => 'Promo Cozy',
            'promo_value' => 4435,
            'patterns' => [
                'i want to rent a room with a discount. my promo code is cozy',
                'my promo code is cozy',
            ],
        ],
        [
            'name' => 'Promo Home',
            'promo_value' => 4437,
            'patterns' => [
                'i want to rent a room with a discount. my promo code is home',
                'my promo code is home',
            ],
        ],
        [
            'name' => 'Promo Move',
            'promo_value' => 4439,
            'patterns' => [
                'i want to rent a room with a discount. my promo code is move',
                'my promo code is move',
            ],
        ],
        [
            'name' => 'Promo Dream',
            'promo_value' => 4441,
            'patterns' => [
                'i want to rent a room with a discount. my promo code is dream',
                'my promo code is dream',
            ],
        ],
        [
            'name' => 'Аквизишен WA Target',
            'source_id' => '32',
            'line_id' => 31,
            'category_id' => 9,
            'patterns' => [
                'i am the owner and want to rent out my flat',
                'rent out my flat on hk island',
            ],
        ],
    ],
];
