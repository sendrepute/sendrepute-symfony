<?php

declare(strict_types=1);

namespace SendRepute\Symfony\Tests;

use PHPUnit\Framework\TestCase;
use SendRepute\Symfony\Controller\CustomerConsoleController;
use SendRepute\Symfony\CustomerApi\ConsoleAuthorizer;
use SendRepute\Symfony\CustomerApi\ConsoleService;
use SendRepute\Symfony\CustomerApi\CustomerApiClient;
use SendRepute\Symfony\CustomerApi\DenyAllConsoleAuthorizer;
use SendRepute\Symfony\CustomerApi\SymfonyTransport;
use SendRepute\Symfony\DependencyInjection\SendReputeExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CustomerConsoleControllerTest extends TestCase
{
    private const KEY = 'sr_live_test_key_0123456789abcdef';

    private function controller(bool $enabled, bool $granted, ?FakeCustomerTransport $t = null): CustomerConsoleController
    {
        $t ??= FakeCustomerTransport::json(['id' => 'acct']);
        $authorizer = $granted ? new class() implements ConsoleAuthorizer {
            public function isGranted(Request $request): bool
            {
                return true;
            }
        } : new DenyAllConsoleAuthorizer();

        return new CustomerConsoleController(static fn () => new ConsoleService(new CustomerApiClient($t, self::KEY), 'Symfony'), $authorizer, $enabled);
    }

    private function request(string $method, string $uri, Session $session, array $server = [], ?string $body = null): Request
    {
        $r = Request::create('https://admin.example'.$uri, $method, [], [], [], $server, $body);
        $r->setSession($session);

        return $r;
    }

    public function testDisabledAndDenyByDefault(): void
    {
        $s = new Session(new MockArraySessionStorage());
        self::assertSame(404, $this->controller(false, true)->index($this->request('GET', '/sr/customer-api', $s))->getStatusCode());
        self::assertSame(403, $this->controller(true, false)->index($this->request('GET', '/sr/customer-api', $s))->getStatusCode());
        self::assertSame(403, $this->controller(true, false)->call($this->request('POST', '/sr/customer-api/call', $s))->getStatusCode());
    }

    public function testCsrfOriginAndPaidQuoteGuards(): void
    {
        $t = FakeCustomerTransport::json(['id' => 'acct']);
        $c = $this->controller(true, true, $t);
        $s = new Session(new MockArraySessionStorage());
        $page = $c->index($this->request('GET', '/sr/customer-api', $s));
        self::assertSame(200, $page->getStatusCode());
        self::assertStringNotContainsString(self::KEY, (string) $page->getContent());
        $catalog = json_decode((string) $c->catalog($this->request('GET', '/sr/customer-api/catalog', $s))->getContent(), true);
        self::assertCount(49, $catalog['operations']);
        $token = $s->get('sendrepute_console_csrf');
        $good = ['HTTP_ORIGIN' => 'https://admin.example', 'HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'];
        $body = '{"operationId":"customerGetAccount"}';
        self::assertSame(403, $c->call($this->request('POST', '/c', $s, ['HTTP_ORIGIN' => 'https://evil.example'] + $good, $body))->getStatusCode());
        self::assertSame(403, $c->call($this->request('POST', '/c', $s, ['HTTP_X_CSRF_TOKEN' => 'nope'] + $good, $body))->getStatusCode());
        self::assertCount(0, $t->calls);
        $ok = $c->call($this->request('POST', '/c', $s, $good, $body));
        self::assertSame(200, $ok->getStatusCode());
        $paid = '{"operationId":"customerPurchaseVip","body":{"expectedPriceMillicents":5},"paidConsent":{"acknowledged":true,"expectedPriceMillicents":5}}';
        self::assertSame(428, $c->call($this->request('POST', '/c', $s, $good, $paid))->getStatusCode());
        self::assertCount(1, $t->calls);
    }

    public function testSymfonyTransportRefusesRedirectsAndBoundsBody(): void
    {
        $http = new MockHttpClient([new MockResponse('{"id":"a"}', ['http_code' => 200, 'response_headers' => ['content-type: application/json']]), new MockResponse(str_repeat('x', 5000), ['http_code' => 200, 'response_headers' => ['content-type: application/json']])]);
        $client = new CustomerApiClient(new SymfonyTransport($http), self::KEY, null, [], 5.0, 1024);
        self::assertSame('a', $client->call('customerGetAccount')['data']['id']);
        $this->expectException(\SendRepute\Symfony\CustomerApi\CustomerApiException::class);
        $client->call('customerGetAccount');
    }

    public function testBundleWiresDisabledConsoleWithoutApiKey(): void
    {
        $container = new ContainerBuilder();
        $container->set(HttpClientInterface::class, new MockHttpClient());
        $container->set('event_dispatcher', new EventDispatcher());
        (new SendReputeExtension())->load([], $container);
        $container->compile();
        self::assertFalse($container->getParameter('send_repute.customer_api.console.enabled'));
        $controller = $container->get(CustomerConsoleController::class);
        $s = new Session(new MockArraySessionStorage());
        self::assertSame(404, $controller->index($this->request('GET', '/x', $s))->getStatusCode());
    }
}
