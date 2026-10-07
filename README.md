KeyCDN REST API Library
=======================

PHP Library for the KeyCDN API

[KeyCDN](https://www.keycdn.com) is a Content Delivery Network to accelerate your web assets.

Please contact us if you got any questions or if you need more functionality: [KeyCDN Support](https://www.keycdn.com/contacts)

## Requirements
- PHP 8.2 or above
- PHP Curl Extension, unless you pass your own PSR-18 HTTP client (see [Options](#options))

## Installation

```
composer require mabahe/keycdn-api
```

## Usage
```php
<?php

require 'vendor/autoload.php';

// create the REST object
$keycdn_api = new KeyCDN\KeyCDN('your_api_key');

// get zone information
$keycdn_api->get('zones.json');


// change zone name and check if successfull
$result = $keycdn_api->post('zones/123.json', [
    'name' => 'newzonename',
]);


// convert json-answer into an array
$answer = json_decode($result, true);

if ($answer['status'] == 'success') {
    echo 'Zonename successfully changed...';
}
```


### get traffic stats

```php
// get traffic stats for the last 30 days
$result = $keycdn_api->get('reports/traffic.json', [
    'zone_id' => 123,
    'start'   => strtotime('-30 days'),
    'end'     => time(),
]);

// convert json-answer into an array
$answer = json_decode($result, true);

// since we get results pr day, we need to sum them
if ($answer['status'] == 'success') {
    $amount = 0;
    foreach ($answer['data']['stats'] as $stats) {
        $amount += $stats['amount'];
    }

    echo 'Traffic last 30 days: ' . $amount;
} else {
    echo 'Something went wrong...';
}

```


### single or bulk url purge

```php
$result = $keycdn_api->delete('zones/purgeurl/{zone_id}.json', [
    'urls' => ['foo-1.kxcdn.com/bar1.jpg','foo-1.kxcdn.com/bar2.jpg'],
]);
```


## Methods

Each of the supported HTTP methods (GET, PUT, POST, DELETE) is produced by an own function in the KeyCDN lib. E.g. POST becomes ```$keycdn_api->post(...);```.

For GET the parameters are sent as query string, for POST, PUT and DELETE as request body
(form-encoded by default, see `body_format` below). This is the same with the built-in cURL
transport and with a PSR-18 client.


## Options

The constructor takes an optional second argument:

```php
$keycdn_api = new KeyCDN\KeyCDN('your_api_key', [
    KeyCDN\KeyCDN::ENDPOINT     => 'https://api.keycdn.com', // API base URL (default)
    KeyCDN\KeyCDN::HTTP_CLIENT  => $psr18Client,             // use a PSR-18 client instead of cURL
    KeyCDN\KeyCDN::BODY_FORMAT  => KeyCDN\KeyCDN::FORMAT_JSON, // send POST/PUT/DELETE bodies as JSON
]);
```

| Option (constant)  | Key               | Default                  | Description |
|--------------------|-------------------|--------------------------|-------------|
| `ENDPOINT`         | `endpoint`        | `https://api.keycdn.com` | API base URL |
| `HTTP_CLIENT`      | `httpclient`      | none (built-in cURL)     | Any PSR-18 client |
| `REQUEST_FACTORY`  | `request_factory` | auto-discovered          | PSR-17 request factory, only used with a PSR-18 client |
| `STREAM_FACTORY`   | `stream_factory`  | auto-discovered          | PSR-17 stream factory, only used with a PSR-18 client |
| `BODY_FORMAT`      | `body_format`     | `form`                   | `form` (`application/x-www-form-urlencoded`) or `json` |

The PSR-17 factories are only looked up when a PSR-18 client is used, so the cURL transport does not
need any PSR implementation installed.


## Error handling

All methods return the raw response body as string. The KeyCDN API reports errors as JSON in the
body (with an HTTP 4xx/5xx status), so check `$answer['status']` as shown above; such responses
do not throw.

A `KeyCDN\KeyCDNException` is thrown when no usable response was received: connection or client
errors (the original exception is available via `getPrevious()`), an empty response body (the
message contains the HTTP status), or parameters that cannot be encoded as JSON.


## Development

```
composer install
composer test
```

The tests use PHPUnit. The cURL transport is tested against a throw-away PHP built-in web server
(`tests/Fixtures/echo-server.php`), the PSR-18 transport against a recording fake client. Both run
on every push and pull request via GitHub Actions (PHP 8.2 to 8.5).

### Integration test with Guzzle

`integration/guzzle` is a separate project with its own `composer.json`. It requires this library
as a dependency (path repository pointing at the checkout) together with `guzzlehttp/guzzle` as PSR-18
client and `guzzlehttp/psr7` as the only PSR-7/PSR-17 implementation, and runs the same fake endpoint
through it. This verifies that the library works when installed as a package and that the PSR-17
factories are discovered from Guzzle.

```
cd integration/guzzle
composer install
composer test
```
