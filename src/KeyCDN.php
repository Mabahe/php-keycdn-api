<?php

namespace KeyCDN;

use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

class KeyCDN
{
    public const HTTP_CLIENT = 'httpclient';

    public const ENDPOINT = 'endpoint';

    public const DEFAULT_ENDPOINT = 'https://api.keycdn.com';

    private string $apiKey;

    private string $endpoint;

    private ?ClientInterface $customHttpClient = null;

    private $requestFactory;
    private $streamFactory;

    public function __construct(string $apiKey, $options = [])
    {
        $endpoint = $options[self::ENDPOINT] ?? self::DEFAULT_ENDPOINT;

        $this->customHttpClient = $options[self::HTTP_CLIENT] ?? null;

        $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = Psr17FactoryDiscovery::findStreamFactory();

        $this->setApiKey($apiKey);
        $this->setEndpoint($endpoint);
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function setApiKey(string $apiKey): KeyCDN
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): KeyCDN
    {
        $this->endpoint = (string)$endpoint;
        return $this;
    }

    /**
     * @throws \Exception
     */
    public function get(string $selectedCall, array $params = []): string
    {
        return $this->execute($selectedCall, 'GET', $params);
    }

    /**
     * @throws \Exception
     */
    public function post(string $selectedCall, array $params = []): string
    {
        return $this->execute($selectedCall, 'POST', $params);
    }

    /**
     * @throws \Exception
     */
    public function put(string $selectedCall, array $params = []): string
    {
        return $this->execute($selectedCall, 'PUT', $params);
    }

    /**
     * @throws \Exception
     */
    public function delete(string $selectedCall, array $params = []): string
    {
        return $this->execute($selectedCall, 'DELETE', $params);
    }

    private function execute($selectedCall, $method, array $params): string
    {
        if ($this->customHttpClient !== null) {
            return $this->executeCustomHttpRequest($selectedCall, $method, $params);
        }
        return $this->executeCurl($selectedCall, $method, $params);

    }

    protected function executeCustomHttpRequest($selectedCall, $method, array $params): string
    {
        $url = rtrim($this->endpoint, '/') . '/' . ltrim($selectedCall, '/');
        $queryStr = http_build_query($params);
        $json = json_encode($params);
        $headers['Content-Type'] = 'application/json';
        $headers['Authorization'] = 'Basic ' . \base64_encode($this->apiKey . ':');
        $body = $this->streamFactory->createStream($json);
        $request = $this->createHttpRequest($method, $url, $headers, $body);
        try {
            $response = $this->customHttpClient->sendRequest($request);
            $response_data = (string)$response->getBody();
            return $response_data;
        } catch (RequestExceptionInterface $e) {
            throw new \Exception($e->getMessage(), $e->getCode());
        } catch (ClientExceptionInterface $e) {
            throw new \Exception($e->getMessage(), $e->getCode());
        }
    }

    private function createHttpRequest(string $method, string $url, array $headers, StreamInterface $body): RequestInterface
    {
        $request = $this->requestFactory->createRequest($method, $url);
        foreach ($headers as $header_key => $header_val) {
            $request = $request->withHeader($header_key, $header_val);
        }
        $request = $request->withBody($body);
        return $request;
    }

    private function executeCurl(string $selectedCall, string $methodType, array $params): string
    {
        $endpoint = rtrim($this->endpoint, '/') . '/' . ltrim($selectedCall, '/');

        // start with curl and prepare accordingly
        $ch = \curl_init();

        // create basic auth information
        \curl_setopt($ch, CURLOPT_USERPWD, $this->apiKey . ':');

        // return transfer as string
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // set curl timeout
        \curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        // retrieve headers
        \curl_setopt($ch, CURLOPT_HEADER, 1);
        \curl_setopt($ch, CURLINFO_HEADER_OUT, 1);

        // set request type
        if (!in_array($methodType, ['POST', 'GET'])) {
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $methodType);
        }

        $queryStr = http_build_query($params);
        // send query-str within url or in post-fields
        if (in_array($methodType, ['POST', 'PUT', 'DELETE'])) {
            $reqUri = $endpoint;
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $queryStr);
        } else {
            $reqUri = $endpoint . '?' . $queryStr;
        }

        // url
        \curl_setopt($ch, CURLOPT_URL, $reqUri);

        // make the request
        $result = \curl_exec($ch);
        $headers = \curl_getinfo($ch);
        $curlError = \curl_error($ch);

        \curl_close($ch);

        // get json_output out of result (remove headers)
        $jsonOutput = substr($result, $headers['header_size']);

        // error catching
        if (!empty($curlError) || empty($jsonOutput)) {
            throw new \Exception("KeyCDN-Error: {$curlError}, Output: {$jsonOutput}");
        }

        return $jsonOutput;
    }
}
