<?php

namespace DzWallet\Http;

use Closure;
use DzWallet\DzWalletClient;
use DzWallet\Exceptions\InvalidSignatureException;
use Illuminate\Http\Request;

/**
 * Route middleware "dzwallet.signature": rejects notifications whose signature is not valid (HTTP 403)
 * and exposes the verified notification as $request->attributes->get('dzwallet.ipn') and its
 * event id as $request->attributes->get('dzwallet.event_id') (null for the older signature).
 * Uses the v2 signature (raw body + timestamp + event id) when DzWallet sends it.
 *
 * It does NOT compare the amount with your order. Do that in your controller:
 *   DzWallet::verifyIpn($request->all(), $order->identifier, $order->amount);
 */
class VerifyDzWalletSignature
{
    public function __construct(private DzWalletClient $client)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            [$ipn, $eventId] = $this->client->verifyNotification($request);
        } catch (InvalidSignatureException $e) {
            return response('Invalid signature', 403);
        }
        $request->attributes->set('dzwallet.ipn', $ipn);
        $request->attributes->set('dzwallet.event_id', $eventId);
        return $next($request);
    }
}
