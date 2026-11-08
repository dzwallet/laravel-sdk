<?php

namespace DzWallet\Data;

final class PaymentInitiated
{
    public function __construct(
        public readonly string $redirectUrl,
        public readonly string $identifier,
        public readonly array $raw = [],
    ) {
    }
}
