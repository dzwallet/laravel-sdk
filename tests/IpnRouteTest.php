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
}
