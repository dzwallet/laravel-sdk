<?php

namespace DzWallet;

use DzWallet\Data\IpnPayload;
use DzWallet\Data\PaymentInitiated;
use DzWallet\Data\PaymentLink;
use DzWallet\Data\PaymentStatus;
use DzWallet\Exceptions\ApiException;
use DzWallet\Exceptions\ConfigurationException;
use DzWallet\Exceptions\ConnectionException;
use DzWallet\Exceptions\InvalidSignatureException;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

class DzWalletClient
{
    public function __construct(private Http $http, private array $config)
    {
    }

    /* ------------------------------------------------------------------ */
    /* Configuration                                                       */
    /* ------------------------------------------------------------------ */

    public function mode(): string
    {
        return ($this->config['mode'] ?? 'test') === 'live' ? 'live' : 'test';
    }

    public function isTestMode(): bool
    {
        return $this->mode() === 'test';
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'https://dzwallet.app'), '/');
    }

    private function publicKey(): string
    {
        $k = (string) ($this->config['public_key'] ?? '');
        if ($k === '') {
            throw new ConfigurationException('DZWALLET_PUBLIC_KEY is not set.');
        }
        return $k;
    }

    private function secretKey(): string
    {
        $k = (string) ($this->config['secret_key'] ?? '');
        if ($k === '') {
            throw new ConfigurationException('DZWALLET_SECRET_KEY is not set.');
        }
        return $k;
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->asForm()
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->withoutRedirecting();
    }

    private function keyedRequest(): PendingRequest
    {
        return $this->request()->withHeaders([
            'X-Public-Key' => $this->publicKey(),
            'X-Secret-Key' => $this->secretKey(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Card payment (website checkout)                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Starts a payment and returns the checkout address to send the customer to.
     *
     * Required keys: identifier (max 20), amount, details (max 100), ipn_url, success_url, cancel_url,
     * site_name, customer[first_name, last_name, email, mobile].
     * Optional: currency (default DZD), gateway_methods (default ["Satim"]), site_logo, checkout_theme
     * (light|dark), shipping_info[], billing_info[].
     *
     * This call is NEVER retried automatically. After a timeout the outcome is unknown:
     * call paymentStatus() with the same identifier before creating another payment.
     */
    public function initiatePayment(array $params): PaymentInitiated
    {
        $params = $params + ['currency' => 'DZD', 'gateway_methods' => ['Satim']];
        $params['currency'] = strtoupper((string) $params['currency']);
        $this->validateInitiate($params);

        $params['public_key'] = $this->publicKey();
        $path = $this->isTestMode() ? '/test/payment/initiate' : '/payment/initiate';

        $json = $this->send(fn () => $this->request()->post($path, $params));

        $url = $json['redirect_url'] ?? null;
        if (!is_string($url) || $url === '') {
            throw new ApiException('The API did not return a checkout address.', [], null);
        }
        return new PaymentInitiated($url, (string) $params['identifier'], $json);
    }

    private function validateInitiate(array $p): void
    {
        $errors = [];
        foreach (['identifier', 'details', 'ipn_url', 'success_url', 'cancel_url', 'site_name'] as $k) {
            if (!isset($p[$k]) || trim((string) $p[$k]) === '') {
                $errors[] = "$k is required";
            }
        }
        if (isset($p['identifier']) && strlen((string) $p['identifier']) > 20) {
            $errors[] = 'identifier must be at most 20 characters';
        }
        if (isset($p['details']) && strlen((string) $p['details']) > 100) {
            $errors[] = 'details must be at most 100 characters';
        }
        if (!isset($p['amount']) || !is_numeric($p['amount']) || (float) $p['amount'] <= 0) {
            $errors[] = 'amount must be a number greater than 0';
        }
        foreach (['ipn_url', 'success_url', 'cancel_url'] as $k) {
            if (isset($p[$k]) && !preg_match('#^https?://#i', (string) $p[$k])) {
                $errors[] = "$k must start with http:// or https://";
            }
        }
        $c = $p['customer'] ?? null;
        if (!is_array($c)) {
            $errors[] = 'customer is required';
        } else {
            foreach (['first_name', 'last_name', 'email', 'mobile'] as $k) {
                if (!isset($c[$k]) || trim((string) $c[$k]) === '') {
                    $errors[] = "customer.$k is required";
                }
            }
        }
        if ($errors) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }
    }

    /**
     * Current state of a payment. Only isPaid() proves the money was received.
     * Safe to retry, so it retries on connection problems.
     */
    public function paymentStatus(string $identifier): PaymentStatus
    {
        if ($identifier === '') {
            throw new InvalidArgumentException('identifier is required');
        }
        $retries = max(0, (int) ($this->config['status_retries'] ?? 2));
        $body = ['public_key' => $this->publicKey(), 'identifier' => $identifier];

        $json = $this->send(fn () => $this->request()->retry($retries + 1, 300, fn ($e) => $e instanceof HttpConnectionException, false)->post('/api/payment/status', $body));

        $data = isset($json['data']) && is_array($json['data']) ? $json['data'] + $json : $json;
        $status = $data['payment_status'] ?? null;
        if (!is_string($status)) {
            throw new ApiException('The API did not return a payment status.', [], null);
        }
        return new PaymentStatus(
            status: $status,
            identifier: (string) ($data['identifier'] ?? $identifier),
            amount: isset($data['amount']) ? (string) $data['amount'] : null,
            currency: $data['currency'] ?? null,
            trx: $data['trx'] ?? null,
            createdAt: $data['created_at'] ?? null,
            raw: $json,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Payment links and QR codes (live keys)                              */
    /* ------------------------------------------------------------------ */

    /**
     * @param array{note?:string, days?:int, reference?:string, ipn_url?:string, email?:string} $options
     *
     * The same reference with the same amount returns the same link (safe to call twice).
     */
    public function createPaymentLink(float|int|string $amount, array $options = []): PaymentLink
    {
        if (!is_numeric($amount) || (float) $amount <= 0) {
            throw new InvalidArgumentException('amount must be a number greater than 0');
        }
        if (isset($options['days']) && ((int) $options['days'] < 1 || (int) $options['days'] > 30)) {
            throw new InvalidArgumentException('days must be between 1 and 30');
        }
        if (isset($options['note']) && mb_strlen((string) $options['note']) > 100) {
            throw new InvalidArgumentException('note must be at most 100 characters');
        }
        if (isset($options['reference']) && !preg_match('/^[A-Za-z0-9_-]{1,15}$/', (string) $options['reference'])) {
            throw new InvalidArgumentException('reference: 1-15 characters, letters, digits, _ and - only');
        }
        if (isset($options['email']) && !filter_var($options['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('email is not valid');
        }

        $body = ['amount' => (string) $amount] + array_filter($options, fn ($v) => $v !== null && $v !== '');
        $json = $this->send(fn () => $this->keyedRequest()->post('/api/payment-links', $body));
        return $this->makeLink($json);
    }

    /** Status of a link, by reference (or transaction number). */
    public function paymentLink(string $reference): PaymentLink
    {
        $json = $this->send(fn () => $this->keyedRequest()->retry(max(0, (int) ($this->config['status_retries'] ?? 2)) + 1, 300, fn ($e) => $e instanceof HttpConnectionException, false)->get('/api/payment-links/' . rawurlencode($reference)));
        return $this->makeLink($json);
    }

    /** Re-opens an expired or closed link: same link and QR code, validity restarts. */
    public function reactivatePaymentLink(string $reference): PaymentLink
    {
        $json = $this->send(fn () => $this->keyedRequest()->post('/api/payment-links/' . rawurlencode($reference) . '/reactivate'));
        return $this->makeLink($json);
    }

    private function makeLink(array $json): PaymentLink
    {
        $d = $json['data'] ?? null;
        if (!is_array($d) || empty($d['url'])) {
            throw new ApiException('The API did not return a payment link.', [], null);
        }
        return new PaymentLink(
            trx: (string) ($d['trx'] ?? ''),
            amount: (string) ($d['amount'] ?? ''),
            currency: (string) ($d['currency'] ?? 'DZD'),
            status: (string) ($d['status'] ?? ''),
            url: (string) $d['url'],
            printUrl: $d['print_url'] ?? null,
            expiresAt: $d['expires_at'] ?? null,
            reference: $d['reference'] ?? null,
            identifier: $d['identifier'] ?? null,
            emailSent: array_key_exists('email_sent', $json) ? (bool) $json['email_sent'] : (array_key_exists('email_sent', $d) ? (bool) $d['email_sent'] : null),
            raw: $json,
        );
    }

    /* ------------------------------------------------------------------ */
    /* IPN                                                                 */
    /* ------------------------------------------------------------------ */

    /** signature = UPPERCASE(HMAC-SHA256(data.amount + identifier, SECRET_KEY)) */
    public function signature(string $amount, string $identifier): string
    {
        return strtoupper(hash_hmac('sha256', $amount . $identifier, $this->secretKey()));
    }

    /**
     * Verifies a notification and returns it. Throws InvalidSignatureException when it is not authentic.
     *
     * Pass $expectedIdentifier and $expectedAmount from YOUR order record: a valid signature proves the
     * message is from DzWallet, comparing with your order proves it is the payment you are waiting for.
     * A signed notification can still be "rejected" or "cancelled": check isSuccess().
     */
    public function verifyIpn(array $payload, ?string $expectedIdentifier = null, float|int|string|null $expectedAmount = null): IpnPayload
    {
        $status = (string) ($payload['status'] ?? '');
        $identifier = (string) ($payload['identifier'] ?? '');
        $signature = (string) ($payload['signature'] ?? '');
        $data = $payload['data'] ?? [];
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($data) || $identifier === '' || $signature === '' || !isset($data['amount'])) {
            throw new InvalidSignatureException('The notification is incomplete.');
        }

        $expected = $this->signature((string) $data['amount'], $identifier);
        if (!hash_equals($expected, strtoupper($signature))) {
            throw new InvalidSignatureException('The notification signature is not valid.');
        }
        if ($expectedIdentifier !== null && !hash_equals($expectedIdentifier, $identifier)) {
            throw new InvalidSignatureException('The notification is for another order.');
        }
        if ($expectedAmount !== null && abs((float) $expectedAmount - (float) $data['amount']) > 0.005) {
            throw new InvalidSignatureException('The notification amount is not the expected amount.');
        }
        return new IpnPayload($status, $identifier, $data, $payload);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** A unique order identifier of at most 20 characters (prefix + random). */
    public static function newIdentifier(string $prefix = 'DZ'): string
    {
        $prefix = substr(preg_replace('/[^A-Za-z0-9]/', '', $prefix), 0, 6);
        $rand = strtoupper(bin2hex(random_bytes(8)));
        return substr($prefix . $rand, 0, 20);
    }

    /**
     * @param callable():Response $call
     */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (HttpConnectionException $e) {
            throw new ConnectionException('Could not reach DzWallet: ' . $e->getMessage(), 0, $e);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        $failed = !$response->successful() || (($json['status'] ?? null) === 'error');

        if ($failed) {
            $msg = $json['message'] ?? null;
            $errors = is_array($msg) ? array_map('strval', array_values($msg)) : ($msg !== null ? [(string) $msg] : []);
            if (!$errors && isset($json['errors']) && is_array($json['errors'])) {
                foreach ($json['errors'] as $list) {
                    foreach ((array) $list as $line) {
                        $errors[] = (string) $line;
                    }
                }
            }
            $text = $errors ? implode('; ', $errors) : 'HTTP ' . $response->status();
            throw new ApiException($text, $errors, $response->status());
        }
        return $json;
    }
}
