<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'scraper' => [
        'python' => env('SCRAPER_PYTHON', base_path('scraper/.venv/bin/python')),
        'script' => env('SCRAPER_SCRIPT', base_path('scraper/x_posts.py')),
        'read_browser_script' => env('SCRAPER_READ_BROWSER_SCRIPT', base_path('scraper/read_browser_cookies.py')),
        'accounts_db' => env('SCRAPER_ACCOUNTS_DB', storage_path('app/scraper/accounts.db')),
        'timeout' => env('SCRAPER_TIMEOUT', 300),
        'storage_state' => env('SCRAPER_STORAGE_STATE', '~/.twitter-mcp/storage_state.json'),
        'account_label' => env('SCRAPER_ACCOUNT_LABEL', 'default'),
        'browser' => env('SCRAPER_BROWSER', 'brave'),
    ],

];
