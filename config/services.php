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

    'frontend_url' => env('FRONTEND_URL'),

    // Tawk.to live chat: API key from Tawk dashboard > Administration > Property Settings (used for Secure mode).
    'tawk' => [
        'api_key' => env('TAWKTO_API_KEY'),
    ],

    'creem' => [
        'api_key' => env('CREEM_API_KEY'),
        'webhook_secret' => env('CREEM_WEBHOOK_SECRET'),
        'products' => [
            'starter_monthly' => env('CREEM_PRODUCT_STARTER_MONTHLY'),
            'starter_yearly' => env('CREEM_PRODUCT_STARTER_YEARLY'),
            'team_monthly' => env('CREEM_PRODUCT_TEAM_MONTHLY'),
            'team_yearly' => env('CREEM_PRODUCT_TEAM_YEARLY'),
            'scale_monthly' => env('CREEM_PRODUCT_SCALE_MONTHLY'),
            'scale_yearly' => env('CREEM_PRODUCT_SCALE_YEARLY'),
        ],
    ],

    // pubsub_topic + pubsub_webhook_secret are NEW — for Gmail's
    // reply-receiving sync (GmailWatchService, GmailPushWebhookController).
    'google_calendar' => [
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
        'pubsub_topic' => env('GOOGLE_PUBSUB_TOPIC'),
        'pubsub_webhook_secret' => env('GOOGLE_PUBSUB_WEBHOOK_SECRET'),
    ],
    // Google reCAPTCHA v2 secret (CaptchaService). Must belong to the SAME key pair as the site key in the frontend
    // (components/common/Recaptcha.jsx), and that key must allow the frontend's domain in the reCAPTCHA admin console.
    'recaptcha' => [
        'secret' => env('RECAPTCHA_SECRET_KEY'),
    ],

    // Zoom (User-managed OAuth app from marketplace.zoom.us): every interviewer connects their OWN Zoom account;
    // the meeting is created on it. Scopes to enable on the app: meeting:write:meeting, meeting:update:meeting,
    // meeting:delete:meeting, user:read:user (or the classic meeting:write, user:read).
    'zoom' => [
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
        'redirect_uri' => env('ZOOM_REDIRECT_URI'),
    ],
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect_uri' => env('MICROSOFT_REDIRECT_URI'),
        'webhook_url' => env('MICROSOFT_WEBHOOK_URL'),
        'webhook_secret' => env('MICROSOFT_WEBHOOK_SECRET'),
    ],

];
