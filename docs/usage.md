# Test Server Usage

Use the test server in integration tests that need a local HTTP endpoint with queued responses and recorded requests. The server is controlled from PHP, but the HTTP server process itself runs on Node.js.

You almost never need this package when testing normal Guzzle clients. Prefer Guzzle's [Mock Handler](https://github.com/guzzle/guzzle/blob/8.0/docs/testing.md#mock-handler) and [History Middleware](https://github.com/guzzle/guzzle/blob/8.0/docs/testing.md#history-middleware) for most client tests. Use the test server when implementing HTTP handlers or when a test must exercise an actual local HTTP server.

The server requires Node.js `^20.19 || ^22.13 || >=24` available as `node`.

## Installation

Install the test server as a development dependency:

```bash
composer require --dev guzzlehttp/test-server:^1.0
```

Alternatively, add it to your project's `composer.json` file:

```json
{
    "require-dev": {
        "guzzlehttp/test-server": "^1.0"
    }
}
```

## Basic Usage

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

## Lifecycle

`GuzzleHttp\Server\Server` provides a static interface to the test server. Any operation on `Server` ensures that the server is running and waits until it can receive requests before returning.

Call `Server::start()` before sending requests to `Server::$url`. Call `Server::stop()` when the test process shuts down. Registering `Server::stop()` as a shutdown function helps avoid leaving the local server running after a failing test.

## Queuing Responses

Use `Server::enqueue()` to define the responses the server should return. It accepts an array of `Psr\Http\Message\ResponseInterface` and `Exception` objects.

When responses are queued, the test server removes any previously queued responses. As the server receives requests, queued responses are dequeued and returned to the request. When the queue is empty, the server returns a 500 response.

```php
Server::enqueue([
    new Response(200, [], 'first'),
    new Response(500, [], 'second'),
]);
```

## Inspecting Requests

Use `Server::received()` to retrieve the requests sent to the server.

```php
$requests = Server::received();

echo $requests[0]->getUri();
echo $requests[0]->getBody();
```

## Clearing Received Requests

Use `Server::flush()` to clear the list of received requests.

```php
Server::flush();

echo count(Server::received());
// 0
```
