<?php

return [
    'vibe_api_base' => rtrim((string) env('OL_BOT_VIBE_API_BASE', 'https://vibecode.bitrix24.com/v1'), '/'),
    'vibe_api_key' => env('OL_BOT_VIBE_API_KEY', env('VIBE_API_KEY')),
    'vibe_env_path' => env('OL_BOT_VIBE_ENV_PATH', base_path('vibecode-openlines-bot/.env')),
    'ai_model' => env('OL_BOT_AI_MODEL', 'bitrix/bitrixgpt-5.5-thinking'),
];
