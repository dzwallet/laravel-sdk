<?php

namespace DzWallet\Http;

use DzWallet\DzWalletClient;
use DzWallet\Events\PaymentNotificationReceived;
use DzWallet\Exceptions\InvalidSignatureException;
use Illuminate\Http\Request;

/**
 * Optional ready-made receiver (enable with DZWALLET_IPN_ENABLED=true).
 * Verifies the signature, fires PaymentNotificationReceived and answers 200 quickly.
 */
class IpnController
{
    public function __invoke(Request $request, DzWalletClient $client)
    {
        try {
            $ipn = $client->verifyIpn($request->all());
        } catch (InvalidSignatureException $e) {
            return response('Invalid signature', 403);
        }

        event(new PaymentNotificationReceived($ipn));

        return response('OK', 200);
    }
}
