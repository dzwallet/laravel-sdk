<?php

namespace DzWallet\Http;

use DzWallet\DzWalletClient;
use DzWallet\Events\PaymentNotificationReceived;
use DzWallet\Exceptions\InvalidSignatureException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Optional ready-made receiver (enable with DZWALLET_IPN_ENABLED=true).
 * Verifies the signature (raw body, timestamp window), ignores events it already handled,
 * fires PaymentNotificationReceived and answers 200 quickly.
 *
 * Duplicate protection uses the application cache. Keep your own order update idempotent too.
 */
class IpnController
{
    public function __invoke(Request $request, DzWalletClient $client)
    {
        try {
            [$ipn, $eventId] = $client->verifyNotification($request);
        } catch (InvalidSignatureException $e) {
            return response('Invalid signature', 403);
        }

        if ($eventId === null || !config('dzwallet.ipn.dedupe', true)) {
            event(new PaymentNotificationReceived($ipn, $eventId));
            return response('OK', 200);
        }

        $seenKey = 'dzwallet:event:' . $eventId;
        $lock = Cache::lock('dzwallet:lock:' . $eventId, 30);
        if (!$lock->get()) {
            return response('Busy', 409); // DzWallet will retry
        }
        try {
            if (Cache::has($seenKey)) {
                return response('Already processed', 200);
            }
            event(new PaymentNotificationReceived($ipn, $eventId));
            Cache::put($seenKey, 1, now()->addDays((int) config('dzwallet.ipn.dedupe_days', 7)));
        } finally {
            $lock->release();
        }
        return response('OK', 200);
    }
}
