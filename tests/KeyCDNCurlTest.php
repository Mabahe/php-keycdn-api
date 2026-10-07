<?php

declare(strict_types=1);

namespace KeyCDN\Tests;

use KeyCDN\KeyCDN;
use KeyCDN\KeyCDNException;
use KeyCDN\Tests\Support\EchoServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The built-in cURL transport, exercised against a throw-away `php -S` server
 * that echoes every request back as JSON (tests/Fixtures/echo-server.php).
 */
#[CoversClass(KeyCDN::class)]
#[CoversClass(KeyCDNException::class)]
#[RequiresPhpExtension('curl')]
final class KeyCDNCurlTest extends TestCase
{
    private static ?EchoServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = EchoServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    private function api(array $options = [], string $apiKey = 'secret'): KeyCDN
    {
        return new KeyCDN($apiKey, [KeyCDN::ENDPOINT => self::$server->url()] + $options);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testGetSendsParamsAsQueryString(): void
    {
        $echo = $this->decode($this->api()->get('reports/traffic.json', ['zone_id' => 123, 'start' => 1]));

        self::assertSame('GET', $echo['method']);
        self::assertSame('/reports/traffic.json', $echo['path']);
        self::assertSame(['zone_id' => '123', 'start' => '1'], $echo['query']);
        self::assertSame('', $echo['body']);
    }

    public function testSendsBasicAuthorizationHeader(): void
    {
        $echo = $this->decode($this->api([], 'my-key')->get('zones.json'));

        self::assertSame('Basic ' . base64_encode('my-key:'), $echo['authorization']);
    }

    public function testPostSendsFormEncodedBody(): void
    {
        $echo = $this->decode($this->api()->post('zones.json', ['name' => 'new zone']));

        self::assertSame('POST', $echo['method']);
        self::assertSame('application/x-www-form-urlencoded', $echo['content_type']);
        self::assertSame('name=new+zone', $echo['body']);
        self::assertSame([], $echo['query']);
    }

    public function testPutSendsFormEncodedBody(): void
    {
        $echo = $this->decode($this->api()->put('zones/1.json', ['name' => 'renamed']));

        self::assertSame('PUT', $echo['method']);
        self::assertSame('/zones/1.json', $echo['path']);
        self::assertSame('name=renamed', $echo['body']);
    }

    public function testDeleteWithoutParamsSendsNoBody(): void
    {
        $echo = $this->decode($this->api()->delete('zones/1.json'));

        self::assertSame('DELETE', $echo['method']);
        self::assertSame('', $echo['body']);
    }

    public function testBulkPurgeSendsUrlListInBody(): void
    {
        $urls = ['foo-1.kxcdn.com/bar1.jpg', 'foo-1.kxcdn.com/bar2.jpg'];

        $echo = $this->decode($this->api()->delete('zones/purgeurl/1.json', ['urls' => $urls]));

        parse_str($echo['body'], $parsed);
        self::assertSame('DELETE', $echo['method']);
        self::assertSame(['urls' => $urls], $parsed);
    }

    public function testBodyCanBeSentAsJson(): void
    {
        $echo = $this->decode(
            $this->api([KeyCDN::BODY_FORMAT => KeyCDN::FORMAT_JSON])->post('zones.json', ['name' => 'x'])
        );

        self::assertSame('application/json', $echo['content_type']);
        self::assertSame(['name' => 'x'], json_decode($echo['body'], true));
    }

    public function testErrorStatusWithBodyIsReturnedNotThrown(): void
    {
        $answer = $this->decode($this->api()->get('unauthorized.json'));

        self::assertSame('error', $answer['status']);
    }

    public function testEmptyResponseThrowsAndMentionsStatus(): void
    {
        $this->expectException(KeyCDNException::class);
        $this->expectExceptionMessage('HTTP 204');

        $this->api()->get('empty');
    }

    public function testConnectionFailureThrows(): void
    {
        // A port that was free a moment ago and has no listener: connection refused.
        $api = new KeyCDN('secret', [KeyCDN::ENDPOINT => 'http://127.0.0.1:' . EchoServer::freePort()]);

        $this->expectException(KeyCDNException::class);
        $this->expectExceptionMessageMatches('/^KeyCDN-Error: .+/');

        $api->get('zones.json');
    }
}
