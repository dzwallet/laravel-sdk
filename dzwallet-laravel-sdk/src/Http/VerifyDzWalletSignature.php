<?php

namespace DzWallet\Http;

use Closure;
use DzWallet\DzWalletClient;
use DzWallet\Exceptions\InvalidSignatureException;
use Illuminate\Http\Request;

/**
 * Route middleware "dzwallet.signature": rejects notifications whose signature is not valid (HTTP 403)
 * and exposes the verified notification as $request->attributes->get('dzwallet.ipn').
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
            $ipn = $this->client->verifyIpn($request->all());
        } catch (InvalidSignatureException $e) {
            return response('Invalid signature', 403);
        }
        $request->attributes->set('dzwallet.ipn', $ipn);
        return $next($request);
    }
}
