# Guzzle test server

This server has long been part of the Guzzle Http client. Since late 2021 it
was extracted to its own package.

The server is useful to use in your integration tests.

```php

use GuzzleHttp\Server\Server;

Server::start();
register_shutdown_function(static function () {
    Server::stop();
});

Server::enqueue([
    new Response(201),
]);

$myHttpClient = MyClient();
$response = $this->makeRequest('GET', Server::$url);
// $response will be 201

$requests = Server::received();
// $request[0] is the one sent by MyClient

```
