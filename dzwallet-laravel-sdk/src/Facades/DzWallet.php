<?php

namespace DzWallet\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \DzWallet\Data\PaymentInitiated initiatePayment(array $params)
 * @method static \DzWallet\Data\PaymentStatus paymentStatus(string $identifier)
 * @method static \DzWallet\Data\PaymentLink createPaymentLink(float|int|string $amount, array $options = [])
 * @method static \DzWallet\Data\PaymentLink paymentLink(string $reference)
 * @method static \DzWallet\Data\PaymentLink reactivatePaymentLink(string $reference)
 * @method static \DzWallet\Data\IpnPayload verifyIpn(array $payload, ?string $expectedIdentifier = null, float|int|string|null $expectedAmount = null)
 * @method static string signature(string $amount, string $identifier)
 * @method static string newIdentifier(string $prefix = 'DZ')
 * @method static string mode()
 * @method static bool isTestMode()
 *
 * @see \DzWallet\DzWalletClient
 */
class DzWallet extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \DzWallet\DzWalletClient::class;
    }
}
