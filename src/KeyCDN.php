<?php

declare(strict_types=1);

namespace KeyCDN;

use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class KeyCDN
{
    /** Option: PSR-18 client used instead of the built-in cURL transport. */
    public const HTTP_CLIENT = 'httpclient';

    /** Option: API base URL. */
    public const ENDPOINT = 'endpoint';

    /** Option: PSR-17 request factory (only used with a PSR-18 client; discovered if omitted). */
    public const REQUEST_FACTORY = 'request_factory';

    /** Option: PSR-17 stream factory (only used with a PSR-18 client; discovered if omitted). */
    public const STREAM_FACTORY = 'stream_factory';

    /** Option: encoding of the request body for POST/PUT/DELETE, FORMAT_FORM (default) or FORMAT_JSON. */
    public const BODY_FORMAT = 'body_format';

    public const FORMAT_FORM = 'form';

    public const FORMAT_JSON = 'json';

    public const DEFAULT_ENDPOINT = 'https://api.keycdn.com';

    private const CURL_TIMEOUT = 60;

    private string $apiKey;

    private string $endpoint;

    private string $bodyFormat;

    private ?ClientInterface $customHttpClient;

    private ?RequestFactoryInterface $requestFactory;

    private ?StreamFactoryInterface $streamFactory;

    /**
     * @param array<string, mixed> $options see the option constants of this class
     *
     * @throws \InvalidArgumentException on an unknown body format
     */
    public function __construct(string $apiKey, array $options = [])
    {
        $this->customHttpClient = $options[self::HTTP_CLIENT] ?? null;
        $this->requestFactory = $options[self::REQUEST_FACTORY] ?? null;
        $this->streamFactory = $options[self::STREAM_FACTORY] ?? null;

        $bodyFormat = $options[self::BODY_FORMAT] ?? self::FORMAT_FORM;
        if (!in_array($bodyFormat, [self::FORMAT_FORM, self::FORMAT_JSON], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid body format %s, expected "%s" or "%s".',
                var_export($bodyFormat, true),
                self::FORMAT_FORM,
                self::FORMAT_JSON
            ));
        }
        $this->bodyFormat = $bodyFormat;

        $this->setApiKey($apiKey);
        $this->setEndpoint($options[self::ENDPOINT] ?? self::DEFAULT_ENDPOINT);
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function setApiKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): static
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    /**
     * @param array<string, mixed> $params sent as query string
     *
     * @throws KeyCDNException
     */
    public function get(string $selectedCall, array $params = []): string
    {
        return $this->execute($selectedCall, 'GET', $params);
    }

    /**
     * @param array<string, mixed> $params sent as request body
     *
     * @throws KeyCDNException
     */
    public function post(string $selectedCall, array $params = []): string
    {
        return $this->execute($selectedCall, 'POST', $params);
    }

    /**
     * @param array<string, mixed> $params sent as request body
     *
     * @throws KeyCDNException
     */
    public function put(string $selectedCall, array $params = []): string
    {
        return $this->execute($selectedCall, 'PUT', $params);
    }

    /**
     * @param array<string, mixed> $params sent as request body
     *
     * @throws KeyCDNException
     */
    public function delete(string $selectedCall, array $params = []): string
    {
        return $this->execute($selectedCall, 'DELETE', $params);
    }

    /**
     * Builds the request once, so the cURL and PSR-18 transports send the same thing.
     *
     * @param array<string, mixed> $params
     *
     * @throws KeyCDNException
     */
    private function execute(string $selectedCall, string $method, array $params): string
    {
        $url = rtrim($this->endpoint, '/') . '/' . ltrim($selectedCall, '/');
        $headers = ['Authorization' => 'Basic ' . base64_encode($this->apiKey . ':')];
        $body = '';

        if ($method === 'GET') {
            if ($params !== []) {
                $url .= '?' . http_build_query($params);
            }
        } elseif ($params !== []) {
            if ($this->bodyFormat === self::FORMAT_JSON) {
                $headers['Content-Type'] = 'application/json';
                $body = $this->encodeJson($params);
            } else {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
                $body = http_build_query($params);
            }
        }

        if ($this->customHttpClient !== null) {
            return $this->sendWithHttpClient($this->customHttpClient, $method, $url, $headers, $body);
        }

        return $this->sendWithCurl($method, $url, $headers, $body);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws KeyCDNException
     */
    private function encodeJson(array $params): string
    {
        try {
            return json_encode($params, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new KeyCDNException('KeyCDN-Error: could not encode parameters as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws KeyCDNException
     */
    private function sendWithHttpClient(
        ClientInterface $client,
        string $method,
        string $url,
        array $headers,
        string $body
    ): string {
        $request = $this->requestFactory()->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== '') {
            $request = $request->withBody($this->streamFactory()->createStream($body));
        }

        try {
            $response = $client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new KeyCDNException('KeyCDN-Error: ' . $e->getMessage(), (int)$e->getCode(), $e);
        }

        $content = (string)$response->getBody();
        if ($content === '') {
            throw new KeyCDNException(sprintf(
                'KeyCDN-Error: empty response (HTTP %d)',
                $response->getStatusCode()
            ));
        }

        return $content;
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws KeyCDNException
     */
    private function sendWithCurl(string $method, string $url, array $headers, string $body): string
    {
        if (!function_exists('curl_init')) {
            throw new KeyCDNException(
                'KeyCDN-Error: the PHP cURL extension is not available; enable it or pass a PSR-18 client '
                . 'via the "' . self::HTTP_CLIENT . '" option.'
            );
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::CURL_TIMEOUT,
            CURLOPT_HTTPHEADER => $headerLines,
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);

        if ($result === false) {
            throw new KeyCDNException('KeyCDN-Error: ' . ($curlError !== '' ? $curlError : 'request failed'));
        }
        if ($result === '') {
            throw new KeyCDNException(sprintf('KeyCDN-Error: empty response (HTTP %d)', $status));
        }

        return (string)$result;
    }

    private function requestFactory(): RequestFactoryInterface
    {
        return $this->requestFactory ??= Psr17FactoryDiscovery::findRequestFactory();
    }

    private function streamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory ??= Psr17FactoryDiscovery::findStreamFactory();
    }
}
