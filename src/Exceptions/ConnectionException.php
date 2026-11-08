<?php

namespace DzWallet\Exceptions;

/**
 * The request could not be completed (timeout, DNS, TLS ...).
 * For a payment creation the outcome is UNKNOWN: check the status with the same identifier before retrying.
 */
class ConnectionException extends DzWalletException
{
}
