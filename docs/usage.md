# Test Server Usage

Use the test server in integration tests that need a local HTTP endpoint with queued responses and recorded requests. The server is controlled from PHP, but the HTTP server process itself runs on Node.js.

The server requires Node.js `^20.19 || ^22.13 || >=24` available as `node`.

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

Call `Server::start()` before sending requests to `Server::$url`. Call `Server::stop()` when the test process shuts down. Registering `Server::stop()` as a shutdown function helps avoid leaving the local server running after a failing test.

## Queuing Responses

Use `Server::enqueue()` to define the responses the server should return. Responses are returned in the order they are queued.

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
