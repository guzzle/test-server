# Guzzle Test Server Documentation

This server has long been part of the Guzzle HTTP client. Since late 2021 it
has lived in its own package.

Use the test server in integration tests that need a local HTTP endpoint with
queued responses and recorded requests. The server is controlled from PHP, but
the HTTP server process itself runs on Node.js.

Requires Node.js `^20.19 || ^22.13 || >=24` available as `node`.

## Usage

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

Call `Server::stop()` when the test process shuts down. Registering it as a
shutdown function helps avoid leaving the local server running after a failing
test.

## Version Guidance

| Version | Status       | PHP Version  |
|---------|--------------|--------------|
| 1.x     | Experimental | >=7.4,<8.6   |
| 0.5     | Latest       | >=7.2.5,<8.6 |
