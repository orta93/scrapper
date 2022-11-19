<?php

return [
    'google_api_key' => env('GOOGLE_API_KEY'),

    'facebook_cookie' => env('FACEBOOK_COOKIE'),

    'instagram_cookie' => env('INSTAGRAM_COOKIE'),
    'instagram_cookie_stories' => env('INSTAGRAM_COOKIE_STORIES'),

    'instagram_versioned_cookie' => env('INSTAGRAM_VERSIONED_COOKIE'),

    'instagram_profile' => env('INSTAGRAM_PROFILE'),
    'instagram_access_token' => env('INSTAGRAM_ACCESS_TOKEN'),

    'tiktok_cookie' => env('TIKTOK_COOKIE'),

    'twitter' => [
        'cookie' => env('TWITTER_COOKIE'),
        'auth' => env('TWITTER_AUTH'),
        'token' => env('TWITTER_TOKEN')
    ]
];