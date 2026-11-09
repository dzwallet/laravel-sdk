<?php

namespace DzWallet\Tests;

use DzWallet\Events\PaymentNotificationReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

class IpnRouteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('dzwallet.ipn.enabled', true);
        $app['config']->set('dzwallet.ipn.path', 'dzwallet/ipn');
    }

    protected function defineRoutes($router): void
    {
        $router->post('/custom-ipn', fn (\Illuminate\Http\Request $r) => response($r->attributes->get('dzwallet.ipn')->identifier))->middleware('dzwallet.signature');
    }

    private function body(string $secret = 'sk_secret_456'): array
    {
        return ['status' => 'success', 'identifier' => 'ORD1', 'signature' => strtoupper(hash_hmac('sha256', '100.00' . 'ORD1', $secret)), 'data' => ['amount' => '100.00', 'currency' => 'DZD']];
    }

    public function test_package_route_fires_event_on_valid_ipn(): void
    {
        Event::fake([PaymentNotificationReceived::class]);
        $this->post('/dzwallet/ipn', $this->body())->assertOk();
        Event::assertDispatched(PaymentNotificationReceived::class, fn ($e) => $e->ipn->identifier === 'ORD1' && $e->ipn->isSuccess());
    }

    public function test_package_route_rejects_bad_signature(): void
    {
        Event::fake([PaymentNotificationReceived::class]);
        $this->post('/dzwallet/ipn', $this->body('wrong'))->assertForbidden();
        Event::assertNotDispatched(PaymentNotificationReceived::class);
    }

    public function test_middleware_alias_protects_a_custom_route(): void
    {
        $this->post('/custom-ipn', $this->body())->assertOk()->assertSee('ORD1');
        $this->post('/custom-ipn', $this->body('wrong'))->assertForbidden();
    }

    private function v2(array $fields, ?int $t = null, string $secret = 'sk_secret_456'): array
    {
        $t ??= time();
        $fields['timestamp'] = $t;
        $raw = http_build_query($fields, '', '&', PHP_QUERY_RFC1738);
        return [$raw, ['X-DzWallet-Signature' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $raw, $secret), 'X-DzWallet-Event-Id' => (string) ($fields['event_id'] ?? '')]];
    }

    private function postRaw(string $uri, string $raw, array $headers)
    {
        $server = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }
        return $this->call('POST', $uri, [], [], [], $server, $raw);
    }

    private function fields(string $eventId = 'evt_1'): array
    {
        return ['event' => 'payment.succeeded', 'event_id' => $eventId, 'status' => 'success', 'identifier' => 'ORD1', 'data' => ['amount' => '100.00', 'currency' => 'DZD']];
    }

    public function test_v2_notification_fires_event_once_even_if_sent_twice(): void
    {
        Event::fake([PaymentNotificationReceived::class]);
        [$raw, $h] = $this->v2($this->fields('evt_dup'));
        $this->postRaw('/dzwallet/ipn', $raw, $h)->assertOk();
        $this->postRaw('/dzwallet/ipn', $raw, $h)->assertOk();
        Event::assertDispatchedTimes(PaymentNotificationReceived::class, 1);
        Event::assertDispatched(PaymentNotificationReceived::class, fn ($e) => $e->eventId === 'evt_dup');
    }

    public function test_v2_rejects_tampered_body_old_timestamp_and_wrong_secret(): void
    {
        Event::fake([PaymentNotificationReceived::class]);
        [$raw, $h] = $this->v2($this->fields('evt_a'));
        $this->postRaw('/dzwallet/ipn', $raw . '&x=1', $h)->assertForbidden();
        [$raw, $h] = $this->v2($this->fields('evt_b'), time() - 3600);
        $this->postRaw('/dzwallet/ipn', $raw, $h)->assertForbidden();
        [$raw, $h] = $this->v2($this->fields('evt_c'), null, 'wrong');
        $this->postRaw('/dzwallet/ipn', $raw, $h)->assertForbidden();
        Event::assertNotDispatched(PaymentNotificationReceived::class);
    }

    public function test_changing_the_event_id_header_does_not_bypass_duplicate_protection(): void
    {
        Event::fake([PaymentNotificationReceived::class]);
        [$raw, $h] = $this->v2($this->fields('evt_x'));
        $h['X-DzWallet-Event-Id'] = 'evt_other';
        $this->postRaw('/dzwallet/ipn', $raw, $h)->assertForbidden();
    }

    public function test_older_signature_is_refused_when_v2_is_required(): void
    {
        config(['dzwallet.ipn.require_v2' => true]);
        $this->post('/dzwallet/ipn', $this->body())->assertForbidden();
    }
}
