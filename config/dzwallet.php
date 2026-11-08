<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('DZWALLET_BASE_URL', 'https://dzwallet.app'),

    /*
    |--------------------------------------------------------------------------
    | Mode: "test" never contacts a real gateway, "live" creates real payments.
    |--------------------------------------------------------------------------
    | Use the test public key with "test" and the live public key with "live".
    */
    'mode' => env('DZWALLET_MODE', 'test'),

    /*
    |--------------------------------------------------------------------------
    | Keys (Merchant dashboard -> Setting -> API Key)
    |--------------------------------------------------------------------------
    | The secret key must stay on your server. Never put it in JavaScript,
    | a mobile app or a public repository.
    */
    'public_key' => env('DZWALLET_PUBLIC_KEY'),
    'secret_key' => env('DZWALLET_SECRET_KEY'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    | Requests that create a payment are never retried automatically, so a
    | slow answer can not create two payments. Read requests (status) retry.
    */
    'timeout' => (int) env('DZWALLET_TIMEOUT', 30),
    'status_retries' => (int) env('DZWALLET_STATUS_RETRIES', 2),

    /*
    |--------------------------------------------------------------------------
    | IPN (instant payment notification) receiver
    |--------------------------------------------------------------------------
    | When enabled, the package registers POST {path}. Every valid, signed
    | notification fires the DzWallet\Events\PaymentNotificationReceived event.
    | Register this URL (or your own) as the ipn_url of your payments, and add
    | your domain to "Your website domains" in the merchant profile.
    */
    'ipn' => [
        'enabled' => (bool) env('DZWALLET_IPN_ENABLED', false),
        'path' => env('DZWALLET_IPN_PATH', 'dzwallet/ipn'),
        'middleware' => [],
    ],
];
