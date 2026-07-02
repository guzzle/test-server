# Test Server Usage

Use the test server for tests that need a local HTTP endpoint with queued responses and recorded requests. The server is controlled from PHP, but the HTTP server process itself runs on Node.js. This guide covers the PHP control API and request/response behavior; setup and version requirements are documented in the project README.

## When to Use the Test Server

You almost never need this package when testing normal Guzzle clients. Prefer Guzzle's [Mock Handler](https://github.com/guzzle/guzzle/blob/8.0/docs/testing.md#mock-handler) and [History Middleware](https://github.com/guzzle/guzzle/blob/8.0/docs/testing.md#history-middleware) for most client tests. Use the test server when implementing HTTP handlers or when a test must exercise an actual local HTTP server.

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

`GuzzleHttp\Server\Server` provides a static interface to the test server. Call `Server::start()` before sending requests to `Server::$url`. `Server::start()` starts the Node.js process when needed and waits until it can receive requests before returning.

`Server::start()` and `Server::stop()` are idempotent. Calling `Server::start()` while the server is already started returns immediately, and calling `Server::stop()` when it is not started is safe.

Operations that use the control API, such as `Server::enqueue()` and `Server::flush()`, create the internal control client and require the server to be reachable. They do not universally start the server for you. `Server::received()` is the exception: before the server has been started, it returns an empty array.

Registering `Server::stop()` as a shutdown function helps avoid leaving the local server running after a failing test.

## Configuration

By default, the test server listens on `127.0.0.1:8126` and `Server::$url` is `http://127.0.0.1:8126/`. Set `Server::$port` and `Server::$url` before the first server operation or internal control client use if a test needs a different port.

```php
Server::$port = 8127;
Server::$url = 'http://127.0.0.1:8127/';

Server::start();
```

## Queuing Responses

Use `Server::enqueue()` to define the responses the server should return. It accepts a `Psr\Http\Message\ResponseInterface` or an array of `ResponseInterface` objects.

When responses are queued, they replace any previously queued responses. As the server receives requests, queued responses are returned in FIFO order. When the queue is empty, the server returns a 500 response and does not record the request.

```php
Server::enqueue([
    new Response(200, [], 'first'),
    new Response(500, [], 'second'),
]);
```

Use `Server::enqueueRaw()` when a PSR-7 response is not suitable, such as when you need exact headers or a raw reason phrase. The response is still written through Node's HTTP response handling.

```php
Server::enqueueRaw(200, 'OK', ['X-Test' => 'raw'], 'response body');
```

Use `Server::enqueueRawBytes()` when a test needs a byte-exact response that bypasses Node's HTTP response handling entirely. The bytes are written straight to the client socket and the connection is closed after they are written. This is useful for emulating misbehaving servers that send protocol-invalid bytes, such as a body after a HEAD response.

```php
Server::enqueueRawBytes("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello");
```

## Inspecting Requests

Use `Server::received()` to retrieve the requests sent to the server. Before the server has been started, it returns an empty array.

```php
$requests = Server::received();

echo $requests[0]->getUri();
echo $requests[0]->getBody();
```

## Clearing Received Requests

Use `Server::flush()` to clear the list of received requests. It does not change the queued responses.

```php
Server::flush();

echo count(Server::received());
// 0
```

## Related

- [Guzzle Testing Documentation](https://github.com/guzzle/guzzle/blob/8.0/docs/testing.md)
