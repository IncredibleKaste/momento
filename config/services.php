<?php

$decodeDomains = static function (mixed $value): array {
    if (! is_string($value)) {
        return [];
    }

    $domains = json_decode($value, true);

    return is_array($domains) ? array_values(array_filter($domains, 'is_string')) : [];
};

$decodeMicrosoftTenants = static function (mixed $value): array {
    if (! is_string($value)) {
        return [];
    }
    $tenants = json_decode($value, true);

    return is_array($tenants) ? $tenants : [];
};

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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', rtrim(config('app.url'), '/').'/auth/oidc/google/callback'),
    ],

    'switch' => [
        'client_id' => env('SWITCH_CLIENT_ID'),
        'client_secret' => env('SWITCH_CLIENT_SECRET'),
        'redirect_uri' => env('SWITCH_REDIRECT_URI', rtrim(config('app.url'), '/').'/auth/oidc/switch/callback'),
        'environment' => env('SWITCH_ENVIRONMENT'),
        'institutional_domains' => $decodeDomains(env('SWITCH_INSTITUTIONAL_DOMAINS', '[]')),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect_uri' => env('MICROSOFT_REDIRECT_URI', rtrim(config('app.url'), '/').'/auth/microsoft/callback'),
        'tenants' => $decodeMicrosoftTenants(env('MICROSOFT_TENANTS', '{}')),
    ],

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

    'problem_reports' => [
        'recipient' => env('PROBLEM_REPORT_RECIPIENT'),
        'mailer' => env('PROBLEM_REPORT_MAILER'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
