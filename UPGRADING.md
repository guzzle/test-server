Guzzle Test Server Upgrade Guide
================================

0.6 to 1.0
----------

Guzzle Test Server 1.0 is a major release that raises the minimum PHP version,
updates the supported Guzzle dependency stack for Guzzle 8 and Guzzle PSR-7
3.x, and tightens the `Server` class contract.

#### PHP Version and Dependencies

Guzzle Test Server 1.0 requires PHP `^7.4 || ^8.0`,
[Guzzle 8.x](https://github.com/guzzle/guzzle/blob/8.0/UPGRADING.md), and
[Guzzle PSR-7 3.x](https://github.com/guzzle/psr7/blob/3.0/UPGRADING.md).
Guzzle Test Server 0.6 supported PHP `^7.2.5 || ^8.0`, Guzzle `^7.12.3`, and
Guzzle PSR-7 `^2.12.3`.

The Node.js runtime requirement is unchanged from 0.6 and remains documented
in the README and package metadata.

If your application still supports PHP 7.2 or 7.3, or still uses Guzzle 7 or
Guzzle PSR-7 2, continue using Guzzle Test Server 0.6 until your minimum
requirements are raised.

#### Server Class Shape

`GuzzleHttp\Server\Server` is now final and cannot be constructed. Use its
static control API directly instead of subclassing or instantiating it.

#### Native PHP Serialization

`Server` no longer supports native PHP `serialize()` or `unserialize()`.
Configure the test server during test setup instead of persisting it.

#### Native Method Signatures

`Server` control and utility methods now declare native parameter and return
types. Update callers and wrappers to pass values that match the documented
types.

#### Public Configuration Properties

`Server::$url` is now typed as `string`, and `Server::$port` is now typed as
`int`. Assign deliberately typed values before starting the server or before
the internal control client is created. Cast and validate values loaded from
environment variables or test configuration before assigning them to these
properties; `Server::start()` also validates the configured port.

```php
use GuzzleHttp\Server\Server;

$port = getenv('GUZZLE_TEST_SERVER_PORT');

if (!is_string($port)) {
    throw new \RuntimeException('GUZZLE_TEST_SERVER_PORT must be set');
}

$validatedPort = filter_var($port, FILTER_VALIDATE_INT, [
    'options' => [
        'min_range' => 1,
        'max_range' => 65535,
    ],
]);

if (!is_int($validatedPort)) {
    throw new \RuntimeException('GUZZLE_TEST_SERVER_PORT must be a valid TCP port');
}

Server::$port = $validatedPort;
Server::$url = sprintf('http://127.0.0.1:%d/', Server::$port);
```

#### Sensitive Stack Trace Arguments

Queued and received raw HTTP records are marked with
`#[\SensitiveParameter]`. PHP 8.2 and later replace those arguments in stack
traces with `SensitiveParameterValue`. PHP 7.4 through 8.1 do not redact trace
arguments.

This does not redact the Node server log, other logs, exception messages,
object properties, wire traffic, captured variables, return values, user
callbacks, or the separate executing object in an explicit backtrace.
