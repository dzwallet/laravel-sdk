<?php

namespace DzWallet\Exceptions;

/**
 * The API answered with an error (HTTP error or {"status":"error"}).
 */
class ApiException extends DzWalletException
{
    /** @var string[] */
    public readonly array $errors;

    public readonly ?int $httpStatus;

    public function __construct(string $message, array $errors = [], ?int $httpStatus = null)
    {
        parent::__construct($message, $httpStatus ?? 0);
        $this->errors = $errors;
        $this->httpStatus = $httpStatus;
    }

    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429;
    }
}
