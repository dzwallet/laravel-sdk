# DzWallet Laravel SDK

PHP / Laravel client for the [DzWallet](https://dzwallet.app) payment API: accept **CIB** and **Edahabia** card payments (through SATIM) on your website, create **payment links and QR codes**, check payment status, and verify the signed payment notification (IPN).

- PHP 8.1+, Laravel 10 / 11 / 12 (uses Laravel's own HTTP client)
- Test mode never contacts a real gateway
- Payments are never retried automatically, so a slow answer cannot create two payments

> Money is only received when the status is `success`. Never mark an order as paid because the customer reached your `success_url`: use the signed IPN, or call `paymentStatus()` from your server.

## Install

While the package is not on Packagist, add it from a folder or your own repository.

```json
"repositories": [{ "type": "path", "url": "../dzwallet-laravel-sdk" }]
```

```bash
composer require dzwallet/laravel-sdk
php artisan vendor:publish --tag=dzwallet-config   # optional
```

## Configure

`.env` (keys are in your merchant dashboard: **Setting → API Key**):

```env
DZWALLET_MODE=test                 # test | live
DZWALLET_PUBLIC_KEY=...            # the TEST public key in test mode, the LIVE one in live mode
DZWALLET_SECRET_KEY=...            # the matching secret key. Server side only, never in JavaScript or a repository
```

Test payments are signed with your **test** secret key and live payments with your **live** secret key, so switch both keys together with the mode.
Live keys exist only after your identity (KYC) is verified.

Register your website domain in the merchant profile (**Your website domains**). `ipn_url`, `success_url` and `cancel_url` must belong to it.

## Take a card payment

```php
use DzWallet\Facades\DzWallet;
use DzWallet\DzWalletClient;

$order = Order::create([...]);                       // keep your own record first
$order->update(['identifier' => DzWalletClient::newIdentifier('ORD')]);   // max 20 characters, unique

$payment = DzWallet::initiatePayment([
    'identifier'  => $order->identifier,
    'amount'      => $order->total,                  // DZD
    'details'     => "Order #{$order->id}",          // max 100 characters
    'ipn_url'     => route('dzwallet.ipn'),
    'success_url' => route('orders.thanks', $order),
    'cancel_url'  => route('orders.cart'),
    'site_name'   => config('app.name'),
    'customer'    => [
        'first_name' => $user->first_name,
        'last_name'  => $user->last_name,
        'email'      => $user->email,
        'mobile'     => $user->phone,
    ],
    // optional: 'currency' => 'DZD' (default), 'gateway_methods' => ['Satim'] (default),
    // 'site_logo', 'checkout_theme' => 'light|dark', 'shipping_info', 'billing_info'
]);

return redirect()->away($payment->redirectUrl);
```

## Receive the notification (IPN)

DzWallet calls your `ipn_url` after the gateway confirms the payment. Two ways to receive it.

**1. Ready-made route.** Set `DZWALLET_IPN_ENABLED=true`. The package registers `POST /dzwallet/ipn` (CSRF-exempt), verifies the signature and fires an event:

```php
use DzWallet\Events\PaymentNotificationReceived;
use Illuminate\Support\Facades\Event;

Event::listen(PaymentNotificationReceived::class, function ($event) {
    $ipn = $event->ipn;                               // signature already verified
    $order = Order::where('identifier', $ipn->identifier)->first();

    if (!$order || !$ipn->isSuccess()) return;
    if ($order->isPaid()) return;                     // the same notification can arrive twice
    if (abs((float) $ipn->amount() - (float) $order->total) > 0.005) return;   // amount must match YOUR order

    $order->markPaid($ipn->trx());
});
```

**2. Your own controller.**

```php
Route::post('/pay/ipn', function (Request $request) {
    $order = Order::where('identifier', $request->input('identifier'))->firstOrFail();

    // throws InvalidSignatureException when the message is not from DzWallet,
    // is for another order, or carries another amount
    $ipn = DzWallet::verifyIpn($request->all(), $order->identifier, $order->total);

    if ($ipn->isSuccess() && !$order->isPaid()) {
        $order->markPaid($ipn->trx());
    }
    return response('OK');       // answer quickly with HTTP 200
})->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
```

Or protect a route with the middleware alias: `->middleware('dzwallet.signature')` (rejects bad signatures with 403 and exposes `$request->attributes->get('dzwallet.ipn')`).

The signature is `UPPERCASE(HMAC-SHA256(data.amount + identifier, SECRET_KEY))`. The package checks it for you with `hash_equals`.

## Check a payment from your server

Use it when the IPN did not arrive, or on your thank-you page:

```php
$status = DzWallet::paymentStatus($order->identifier);

$status->isPaid();     // true only for "success"
$status->status;       // initiated | pending | success | rejected | cancelled
$status->isFinal();
$status->trx;
```

## Payment links and QR codes (live keys)

```php
$link = DzWallet::createPaymentLink(2500, [
    'reference' => 'ORD1042',     // optional, max 15: letters, digits, _ -   (same reference + amount = same link)
    'days'      => 7,             // 1-30
    'note'      => 'Room 12',     // max 100
    'email'     => 'customer@example.dz',   // the link is emailed
    'ipn_url'   => 'https://shop.example.dz/pay/ipn',
]);

$link->url;  $link->printUrl;  $link->status;  $link->expiresAt;  $link->isOpen();

DzWallet::paymentLink('ORD1042');              // status
DzWallet::reactivatePaymentLink('ORD1042');    // same link and QR code, validity restarts
```

## Errors

| Exception | When |
|---|---|
| `InvalidArgumentException` | a parameter is wrong; nothing was sent |
| `ConfigurationException` | a key is missing |
| `ApiException` | DzWallet answered with an error (`->errors`, `->httpStatus`, `->isRateLimited()`) |
| `ConnectionException` | timeout / network. For `initiatePayment` the result is **unknown**: call `paymentStatus()` with the same identifier before creating another payment |
| `InvalidSignatureException` | a notification is not authentic, or is for another order or amount |

All extend `DzWallet\Exceptions\DzWalletException`. Limits: payment creation and status are limited to 60 requests per minute per IP (HTTP 429).

## Security checklist

- The secret key lives only in `.env` on the server.
- Always pass the expected identifier and amount to `verifyIpn()`.
- Make the order update idempotent: ignore an order that is already paid.
- Use HTTPS with a valid certificate on your `ipn_url`.
- Test with `DZWALLET_MODE=test` first. In test mode you get a simulated payment page and a real signed IPN.

## Tests

```bash
composer install
vendor/bin/phpunit
```

The suite uses `Http::fake()`: it never calls DzWallet and never creates a payment.

MIT licence.
