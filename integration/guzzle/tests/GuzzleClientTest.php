<?php

declare(strict_types=1);

namespace KeyCDN\IntegrationTests\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use KeyCDN\KeyCDN;
use KeyCDN\KeyCDNException;
use KeyCDN\Tests\Support\EchoServer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;

// The echo server lives in the library's own test suite and has no PHPUnit dependency.
require_once __DIR__ . '/../../../tests/Support/EchoServer.php';

/**
 * mabahe/keycdn-api installed as a dependency (see ../composer.json), driven by
 * Guzzle as PSR-18 client with guzzlehttp/psr7 as the only PSR-7/PSR-17
 * implementation, against the fake endpoint from tests/Fixtures/echo-server.php.
 */
final class GuzzleClientTest extends TestCase
{
    private static ?EchoServer $server = null;

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = EchoServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    /**
     * No PSR-17 factories are passed: they have to be discovered, and
     * guzzlehttp/psr7 is the only implementation that is installed.
     */
    private function api(array $options = []): KeyCDN
    {
        $stack = HandlerStack::create();
        $stack->push(Middleware::history($this->history));

        return new KeyCDN('secret', [
            KeyCDN::ENDPOINT => self::$server->url(),
            KeyCDN::HTTP_CLIENT => new Client(['handler' => $stack]),
        ] + $options);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testRequestsAreBuiltWithGuzzlePsr7(): void
    {
        $this->api()->post('zones.json', ['name' => 'x']);

        self::assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        self::assertInstanceOf(GuzzleRequest::class, $request);
        self::assertSame('name=x', (string)$request->getBody());
    }

    public function testGetSendsParamsAsQueryString(): void
    {
        $echo = $this->decode($this->api()->get('reports/traffic.json', ['zone_id' => 123, 'start' => 1]));

        self::assertSame('GET', $echo['method']);
        self::assertSame('/reports/traffic.json', $echo['path']);
        self::assertSame(['zone_id' => '123', 'start' => '1'], $echo['query']);
        self::assertSame('', $echo['body']);
        self::assertSame('Basic ' . base64_encode('secret:'), $echo['authorization']);
    }

    public function testPostSendsFormEncodedBody(): void
    {
        $echo = $this->decode($this->api()->post('zones.json', ['name' => 'new zone']));

        self::assertSame('POST', $echo['method']);
        self::assertSame('application/x-www-form-urlencoded', $echo['content_type']);
        self::assertSame('name=new+zone', $echo['body']);
    }

    public function testPostCanSendJsonBody(): void
    {
        $echo = $this->decode(
            $this->api([KeyCDN::BODY_FORMAT => KeyCDN::FORMAT_JSON])->post('zones.json', ['name' => 'x'])
        );

        self::assertSame('application/json', $echo['content_type']);
        self::assertSame(['name' => 'x'], json_decode($echo['body'], true));
    }

    public function testBulkPurgeSendsUrlListInDeleteBody(): void
    {
        $urls = ['foo-1.kxcdn.com/bar1.jpg', 'foo-1.kxcdn.com/bar2.jpg'];

        $echo = $this->decode($this->api()->delete('zones/purgeurl/1.json', ['urls' => $urls]));

        parse_str($echo['body'], $parsed);
        self::assertSame('DELETE', $echo['method']);
        self::assertSame(['urls' => $urls], $parsed);
    }

    public function testErrorStatusWithBodyIsReturnedNotThrown(): void
    {
        // Guzzle's PSR-18 sendRequest() does not throw on 4xx/5xx, so the JSON error body arrives as usual.
        $answer = $this->decode($this->api()->get('unauthorized.json'));

        self::assertSame('error', $answer['status']);
    }

    public function testEmptyResponseThrowsAndMentionsStatus(): void
    {
        $this->expectException(KeyCDNException::class);
        $this->expectExceptionMessage('HTTP 204');

        $this->api()->get('empty');
    }

    public function testConnectionFailureIsWrappedAndChained(): void
    {
        $api = new KeyCDN('secret', [
            KeyCDN::ENDPOINT => 'http://127.0.0.1:' . EchoServer::freePort(),
            KeyCDN::HTTP_CLIENT => new Client(),
        ]);

        try {
            $api->get('zones.json');
            self::fail('Expected a KeyCDNException');
        } catch (KeyCDNException $e) {
            self::assertInstanceOf(ConnectException::class, $e->getPrevious());
            self::assertInstanceOf(NetworkExceptionInterface::class, $e->getPrevious());
        }
    }
}
