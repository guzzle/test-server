# Guzzle Test Server

`guzzlehttp/test-server` is a testing helper for PHP HTTP clients. It starts a local Node.js HTTP server, lets your tests queue responses, and records the requests your client sends.

Use this package in integration tests when you need a predictable HTTP endpoint. It is not intended to be a general-purpose web server or a production dependency.

## Installation

```bash
composer require --dev guzzlehttp/test-server
```

The server requires Node.js `^20.19 || ^22.13 || >=24` available as `node`.

## Version Guidance

| Version | Status       | PHP Version  |
|---------|--------------|--------------|
| 1.x     | Experimental | >=7.4,<8.6   |
| 0.5     | Latest       | >=7.2.5,<8.6 |

## Quick Start

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Server\Server;

$client = new Client();

Server::start();
register_shutdown_function(static function (): void {
    Server::stop();
});

Server::enqueue([
    new Response(201),
]);

$response = $client->request('GET', Server::$url);
echo $response->getStatusCode();
// 201

$requests = Server::received();
echo $requests[0]->getMethod();
// GET
```

## Documentation

- [Full documentation](docs/index.md)
- [Changelog](CHANGELOG.md)

## License

Guzzle Test Server is made available under the MIT License (MIT). Please see [License File](LICENSE) for more information.
