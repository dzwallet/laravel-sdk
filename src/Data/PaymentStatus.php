<?php

namespace DzWallet\Data;

final class PaymentStatus
{
    public const INITIATED = 'initiated';
    public const PENDING = 'pending';
    public const SUCCESS = 'success';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    public function __construct(
        public readonly string $status,
        public readonly string $identifier,
        public readonly ?string $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $trx = null,
        public readonly ?string $createdAt = null,
        public readonly array $raw = [],
    ) {
    }

    /** Only "success" means the money was received. */
    public function isPaid(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::SUCCESS, self::REJECTED, self::CANCELLED], true);
    }
}
