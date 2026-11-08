<?php

namespace DzWallet\Data;

final class PaymentLink
{
    public function __construct(
        public readonly string $trx,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $status,
        public readonly string $url,
        public readonly ?string $printUrl = null,
        public readonly ?string $expiresAt = null,
        public readonly ?string $reference = null,
        public readonly ?string $identifier = null,
        public readonly ?bool $emailSent = null,
        public readonly array $raw = [],
    ) {
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending';
    }
}
