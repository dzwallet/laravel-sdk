<?php

namespace DzWallet\Tests;

use DzWallet\Data\PaymentStatus;
use DzWallet\DzWalletClient;
use DzWallet\Exceptions\ApiException;
use DzWallet\Exceptions\ConnectionException;
use DzWallet\Exceptions\ConfigurationException;
use DzWallet\Exceptions\InvalidSignatureException;
use DzWallet\Facades\DzWallet;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class ClientTest extends TestCase
{
    private function payment(array $over = []): array
    {
        return $over + [
            'identifier' => 'ORD1042',
            'amount' => 5000,
            'details' => 'Hotel booking',
            'ipn_url' => 'https://shop.test/ipn',
            'success_url' => 'https://shop.test/ok',
            'cancel_url' => 'https://shop.test/ko',
            'site_name' => 'My shop',
            'customer' => ['first_name' => 'Amine', 'last_name' => 'B', 'email' => 'a@b.dz', 'mobile' => '0555000000'],
        ];
    }

    public function test_initiate_in_test_mode_uses_the_test_endpoint_and_defaults(): void
    {
        Http::fake(['dzwallet.test/*' => Http::response(['status' => 'success', 'message' => ['Payment initiated'], 'redirect_url' => 'https://dzwallet.test/pay/abc'])]);

        $r = DzWallet::initiatePayment($this->payment());

        $this->assertSame('https://dzwallet.test/pay/abc', $r->redirectUrl);
        $this->assertSame('ORD1042', $r->identifier);
        Http::assertSent(function (Request $req) {
            $d = $req->data();
            return $req->url() === 'https://dzwallet.test/test/payment/initiate'
                && $req->method() === 'POST'
                && $d['public_key'] === 'pk_test_123'
                && $d['currency'] === 'DZD'
                && $d['gateway_methods'] === ['Satim']
                && $d['customer']['email'] === 'a@b.dz'
                && !isset($d['secret_key']);
        });
    }

    public function test_initiate_in_live_mode_uses_the_live_endpoint(): void
    {
        config(['dzwallet.mode' => 'live']);
        $this->app->forgetInstance(DzWalletClient::class);
        Http::fake(['*' => Http::response(['status' => 'success', 'redirect_url' => 'https://dzwallet.test/pay/x'])]);

        app(DzWalletClient::class)->initiatePayment($this->payment());

        Http::assertSent(fn (Request $r) => $r->url() === 'https://dzwallet.test/payment/initiate');
    }

    public function test_initiate_error_becomes_an_api_exception(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => ['Invalid api key']], 200)]);
        try {
            DzWallet::initiatePayment($this->payment());
            $this->fail('expected exception');
        } catch (ApiException $e) {
            $this->assertSame('Invalid api key', $e->getMessage());
            $this->assertSame(['Invalid api key'], $e->errors);
        }
    }

    public function test_rate_limit_is_reported(): void
    {
        Http::fake(['*' => Http::response('Too Many Requests', 429)]);
        try {
            DzWallet::paymentStatus('ORD1');
            $this->fail('expected exception');
        } catch (ApiException $e) {
            $this->assertTrue($e->isRateLimited());
        }
    }

    public function test_initiate_validates_before_calling_the_api(): void
    {
        Http::fake();
        $this->expectException(InvalidArgumentException::class);
        try {
            DzWallet::initiatePayment($this->payment(['identifier' => str_repeat('A', 21), 'amount' => 0]));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_initiate_is_not_retried_after_a_connection_failure(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new HttpConnectionException('timeout');
        });
        try {
            DzWallet::initiatePayment($this->payment());
            $this->fail('expected exception');
        } catch (ConnectionException $e) {
            $this->assertSame(1, $calls);
        }
    }

    public function test_payment_status_paid(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'payment_status' => 'success', 'identifier' => 'ORD1042', 'amount' => '5000.00', 'currency' => 'DZD', 'trx' => 'TRXABC', 'created_at' => '2026-11-08 10:00:00'])]);

        $s = DzWallet::paymentStatus('ORD1042');

        $this->assertTrue($s->isPaid());
        $this->assertTrue($s->isFinal());
        $this->assertSame('TRXABC', $s->trx);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://dzwallet.test/api/payment/status' && $r->data() === ['public_key' => 'pk_test_123', 'identifier' => 'ORD1042']);
    }

    public function test_payment_status_pending_is_not_paid(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'payment_status' => 'pending', 'identifier' => 'X'])]);
        $s = DzWallet::paymentStatus('X');
        $this->assertFalse($s->isPaid());
        $this->assertFalse($s->isFinal());
        $this->assertSame(PaymentStatus::PENDING, $s->status);
    }

    public function test_payment_status_not_found(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Payment not found'])]);
        $this->expectExceptionMessage('Payment not found');
        DzWallet::paymentStatus('NOPE');
    }

    public function test_create_payment_link_sends_both_keys_as_headers(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'success', 'message' => 'ok', 'email_sent' => true,
            'data' => ['trx' => 'T1', 'amount' => '2500.00', 'currency' => 'DZD', 'status' => 'pending', 'url' => 'https://dzwallet.test/l/T1', 'print_url' => 'https://dzwallet.test/p/T1', 'expires_at' => '2026-11-15 10:00:00', 'reference' => 'ORD1042', 'identifier' => 'QRA03ORD1042'],
        ])]);

        $l = DzWallet::createPaymentLink(2500, ['reference' => 'ORD1042', 'days' => 7, 'note' => 'Room 12', 'email' => 'c@d.dz']);

        $this->assertSame('https://dzwallet.test/l/T1', $l->url);
        $this->assertTrue($l->isOpen());
        $this->assertTrue($l->emailSent);
        Http::assertSent(function (Request $r) {
            return $r->url() === 'https://dzwallet.test/api/payment-links'
                && $r->hasHeader('X-Public-Key', 'pk_test_123')
                && $r->hasHeader('X-Secret-Key', 'sk_secret_456')
                && $r->hasHeader('Accept', 'application/json')
                && $r->data()['amount'] === '2500' && $r->data()['reference'] === 'ORD1042';
        });
    }

    public function test_payment_link_option_validation(): void
    {
        Http::fake();
        foreach ([['days' => 31], ['days' => 0], ['reference' => 'has space'], ['reference' => str_repeat('a', 16)], ['note' => str_repeat('x', 101)], ['email' => 'nope']] as $opts) {
            try {
                DzWallet::createPaymentLink(1000, $opts);
                $this->fail('expected exception for ' . json_encode($opts));
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
        Http::assertNothingSent();
    }

    public function test_get_and_reactivate_link(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['trx' => 'T1', 'amount' => '10', 'currency' => 'DZD', 'status' => 'expired', 'url' => 'https://x/l']])]);
        $this->assertTrue(DzWallet::paymentLink('REF 1')->isExpired());
        DzWallet::reactivatePaymentLink('REF 1');
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://dzwallet.test/api/payment-links/REF%201');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://dzwallet.test/api/payment-links/REF%201/reactivate');
    }

    public function test_missing_keys_are_reported(): void
    {
        config(['dzwallet.secret_key' => null]);
        $this->app->forgetInstance(DzWalletClient::class);
        $this->expectException(ConfigurationException::class);
        app(DzWalletClient::class)->createPaymentLink(1000);
    }

    public function test_new_identifier_is_at_most_20_chars_and_unique(): void
    {
        $a = DzWalletClient::newIdentifier('ORD-');
        $b = DzWalletClient::newIdentifier('ORD-');
        $this->assertLessThanOrEqual(20, strlen($a));
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]+$/', $a);
    }

    /* ------------------------------ IPN ------------------------------ */

    private function ipn(string $status = 'success', string $amount = '5000.00000000', string $identifier = 'ORD1042', ?string $secret = null): array
    {
        $sig = strtoupper(hash_hmac('sha256', $amount . $identifier, $secret ?? 'sk_secret_456'));
        return ['status' => $status, 'identifier' => $identifier, 'signature' => $sig, 'data' => ['payment_trx' => 'TRXABC', 'amount' => $amount, 'payment_type' => 'checkout', 'payment_timestamp' => '2026-11-08 10:00:00', 'currency' => 'DZD']];
    }

    public function test_valid_ipn_is_accepted(): void
    {
        $i = DzWallet::verifyIpn($this->ipn(), 'ORD1042', 5000);
        $this->assertTrue($i->isSuccess());
        $this->assertSame('TRXABC', $i->trx());
        $this->assertSame('5000.00000000', $i->amount());
    }

    public function test_ipn_signature_is_case_insensitive_and_data_may_be_json(): void
    {
        $p = $this->ipn();
        $p['signature'] = strtolower($p['signature']);
        $p['data'] = json_encode($p['data']);
        $this->assertSame('ORD1042', DzWallet::verifyIpn($p)->identifier);
    }

    public function test_ipn_with_wrong_secret_is_rejected(): void
    {
        $this->expectException(InvalidSignatureException::class);
        DzWallet::verifyIpn($this->ipn('success', '5000.00', 'ORD1042', 'another-secret'));
    }

    public function test_ipn_with_changed_amount_is_rejected(): void
    {
        $p = $this->ipn();
        $p['data']['amount'] = '1.00';
        $this->expectException(InvalidSignatureException::class);
        DzWallet::verifyIpn($p);
    }

    public function test_ipn_for_another_order_or_amount_is_rejected(): void
    {
        foreach ([['OTHER', 5000], ['ORD1042', 4000]] as [$id, $amount]) {
            try {
                DzWallet::verifyIpn($this->ipn(), $id, $amount);
                $this->fail('expected exception');
            } catch (InvalidSignatureException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_incomplete_ipn_is_rejected(): void
    {
        $this->expectException(InvalidSignatureException::class);
        DzWallet::verifyIpn(['status' => 'success']);
    }

    public function test_signed_rejected_ipn_is_not_a_success(): void
    {
        $i = DzWallet::verifyIpn($this->ipn('rejected'));
        $this->assertFalse($i->isSuccess());
    }
}
