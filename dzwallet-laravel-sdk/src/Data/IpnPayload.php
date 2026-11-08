<?php

namespace DzWallet\Data;

/**
 * A notification whose signature has ALREADY been verified.
 */
final class IpnPayload
{
    public function __construct(
        public readonly string $status,
        public readonly string $identifier,
        public readonly array $data,
        public readonly array $raw = [],
    ) {
    }

    public function isSuccess(): bool
    {
        return strtolower($this->status) === 'success';
    }

    public function amount(): string
    {
        return (string) ($this->data['amount'] ?? '');
    }

    /** Unix time or date string of the payment, as sent by DzWallet. */
    public function timestamp(): ?string
    {
        return isset($this->data['payment_timestamp']) ? (string) $this->data['payment_timestamp'] : null;
    }

    public function currency(): ?string
    {
        return isset($this->data['currency']) ? (string) $this->data['currency'] : null;
    }

    public function trx(): ?string
    {
        foreach (['payment_trx', 'trx', 'transaction_id'] as $k) {
            if (isset($this->data[$k])) {
                return (string) $this->data[$k];
            }
        }
        return null;
    }
}
