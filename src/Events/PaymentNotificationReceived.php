<?php

namespace DzWallet\Events;

use DzWallet\Data\IpnPayload;

/**
 * Fired for every signed notification. DzWallet may send the same notification more than once:
 * your listener must be idempotent (ignore an order that is already paid).
 * $eventId is the unique id of the event (identical on every retry); null with the older signature.
 */
class PaymentNotificationReceived
{
    public function __construct(public readonly IpnPayload $ipn, public readonly ?string $eventId = null)
    {
    }
}
