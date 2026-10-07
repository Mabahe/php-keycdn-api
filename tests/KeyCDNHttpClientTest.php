<?php

declare(strict_types=1);

namespace KeyCDN\Tests;

use KeyCDN\KeyCDN;
use KeyCDN\KeyCDNException;
use KeyCDN\Tests\Support\RecordingClient;
use KeyCDN\Tests\Support\TransportFailure;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PSR-18 transport, exercised against a recording fake client.
 */
#[CoversClass(KeyCDN::class)]
#[CoversClass(KeyCDNException::class)]
final class KeyCDNHttpClientTest extends TestCase
{
    private const OK_BODY = '{"status":"success"}';

    private function api(RecordingClient $client, array $options = []): KeyCDN
    {
        return new KeyCDN('secret', [KeyCDN::HTTP_CLIENT => $client] + $options);
    }

    private function okClient(): RecordingClient
    {
        return new RecordingClient(new Response(200, [], self::OK_BODY));
    }

    public function testGetSendsParamsAsQueryStringWithoutBody(): void
    {
        $client = $this->okClient();

        $result = $this->api($client)->get('reports/traffic.json', ['zone_id' => 123, 'start' => 1]);

        $request = $client->lastRequest;
        self::assertSame(self::OK_BODY, $result);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.keycdn.com/reports/traffic.json?zone_id=123&start=1', (string)$request->getUri());
        self::assertSame('', (string)$request->getBody());
        self::assertFalse($request->hasHeader('Content-Type'));
    }

    public function testGetWithoutParamsHasNoTrailingQuestionMark(): void
    {
        $client = $this->okClient();

        $this->api($client)->get('zones.json');

        self::assertSame('https://api.keycdn.com/zones.json', (string)$client->lastRequest->getUri());
    }

    #[DataProvider('bodyMethods')]
    public function testBodyMethodsSendFormEncodedBodyByDefault(string $method): void
    {
        $client = $this->okClient();

        $this->api($client)->{strtolower($method)}('zones/1.json', ['name' => 'new zone']);

        $request = $client->lastRequest;
        self::assertSame($method, $request->getMethod());
        self::assertSame('https://api.keycdn.com/zones/1.json', (string)$request->getUri());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('name=new+zone', (string)$request->getBody());
    }

    /** @return array<string, array{string}> */
    public static function bodyMethods(): array
    {
        return ['POST' => ['POST'], 'PUT' => ['PUT'], 'DELETE' => ['DELETE']];
    }

    public function testBodyCanBeSentAsJson(): void
    {
        $client = $this->okClient();
        $urls = ['foo-1.kxcdn.com/bar1.jpg', 'foo-1.kxcdn.com/bar2.jpg'];

        $this->api($client, [KeyCDN::BODY_FORMAT => KeyCDN::FORMAT_JSON])
            ->delete('zones/purgeurl/1.json', ['urls' => $urls]);

        $request = $client->lastRequest;
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(['urls' => $urls], json_decode((string)$request->getBody(), true));
    }

    public function testBodyMethodWithoutParamsSendsNoBody(): void
    {
        $client = $this->okClient();

        $this->api($client)->delete('zones/1.json');

        self::assertSame('', (string)$client->lastRequest->getBody());
        self::assertFalse($client->lastRequest->hasHeader('Content-Type'));
    }

    public function testSendsBasicAuthorizationHeaderWithApiKeyAsUsername(): void
    {
        $client = $this->okClient();

        $this->api($client)->get('zones.json');

        self::assertSame('Basic ' . base64_encode('secret:'), $client->lastRequest->getHeaderLine('Authorization'));
    }

    public function testSettersAffectLaterRequests(): void
    {
        $client = $this->okClient();
        $api = $this->api($client);

        $result = $api->setApiKey('other')->setEndpoint('https://example.test/v2/');
        $api->get('/zones.json');

        self::assertSame($api, $result);
        self::assertSame('other', $api->getApiKey());
        self::assertSame('https://example.test/v2/', $api->getEndpoint());
        self::assertSame('https://example.test/v2/zones.json', (string)$client->lastRequest->getUri());
        self::assertSame('Basic ' . base64_encode('other:'), $client->lastRequest->getHeaderLine('Authorization'));
    }

    public function testEndpointOptionAndDefault(): void
    {
        self::assertSame(KeyCDN::DEFAULT_ENDPOINT, (new KeyCDN('k'))->getEndpoint());
        self::assertSame(
            'https://example.test',
            (new KeyCDN('k', [KeyCDN::ENDPOINT => 'https://example.test']))->getEndpoint()
        );
    }

    public function testErrorStatusWithBodyIsReturnedNotThrown(): void
    {
        $body = '{"status":"error","description":"Unauthorized"}';
        $client = new RecordingClient(new Response(401, [], $body));

        self::assertSame($body, $this->api($client)->get('zones.json'));
    }

    public function testEmptyResponseThrowsAndMentionsStatus(): void
    {
        $client = new RecordingClient(new Response(502));

        $this->expectException(KeyCDNException::class);
        $this->expectExceptionMessage('HTTP 502');

        $this->api($client)->get('zones.json');
    }

    public function testClientExceptionIsWrappedAndChained(): void
    {
        $failure = new TransportFailure('connection reset', 7);
        $client = new RecordingClient($failure);

        try {
            $this->api($client)->get('zones.json');
            self::fail('Expected a KeyCDNException');
        } catch (KeyCDNException $e) {
            self::assertStringContainsString('connection reset', $e->getMessage());
            self::assertSame(7, $e->getCode());
            self::assertSame($failure, $e->getPrevious());
        }
    }

    public function testUnencodableJsonParamsThrow(): void
    {
        $api = $this->api($this->okClient(), [KeyCDN::BODY_FORMAT => KeyCDN::FORMAT_JSON]);

        $this->expectException(KeyCDNException::class);
        $this->expectExceptionMessage('JSON');

        $api->post('zones.json', ['name' => "\xB1\x31"]);
    }

    public function testInvalidBodyFormatIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new KeyCDN('k', [KeyCDN::BODY_FORMAT => 'xml']);
    }

    public function testExplicitPsr17FactoriesAreUsed(): void
    {
        $client = $this->okClient();
        $factory = new Psr17Factory();

        $this->api($client, [KeyCDN::REQUEST_FACTORY => $factory, KeyCDN::STREAM_FACTORY => $factory])
            ->post('zones.json', ['name' => 'x']);

        self::assertSame('name=x', (string)$client->lastRequest->getBody());
    }
}
