<?php

use Illuminate\Support\Str;

return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web'],

    'waits' => [
        'redis:default' => 60,
        'redis:bp-pauses' => 120,
        'redis:disk' => 300,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [
    ],

    'silenced_tags' => [
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => true,

    'memory_limit' => 128,

    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 180,
            'nice' => 0,
        ],
        'supervisor-pauses' => [
            'connection' => 'redis',
            'queue' => ['bp-pauses'],
            'balance' => 'simple',
            'maxProcesses' => 2,
            'maxTime' => 3600,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 5,
            'timeout' => 120,
            'nice' => 0,
        ],
        'supervisor-disk' => [
            'connection' => 'redis',
            'queue' => ['disk'],
            'balance' => 'simple',
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 3600,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-default' => [
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-pauses' => [
                'maxProcesses' => 2,
            ],
            'supervisor-disk' => [
                'maxProcesses' => 1,
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'maxProcesses' => 1,
            ],
            'supervisor-pauses' => [
                'maxProcesses' => 1,
            ],
            'supervisor-disk' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],

];
