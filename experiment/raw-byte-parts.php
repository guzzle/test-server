<?php

declare(strict_types=1);

// Temporary CI check of Server::enqueueRawByteParts(). Not for merge.

require __DIR__.'/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Server\Server;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ').$label."\n";
    if (!$ok) {
        ++$failures;
    }
};

$connect = static function () {
    $socket = \stream_socket_client('tcp://127.0.0.1:'.Server::$port, $errno, $errstr, 5);
    if ($socket === false) {
        throw new \RuntimeException("connect failed: $errstr");
    }
    \fwrite($socket, "GET /raw HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
    \stream_set_timeout($socket, 10);

    return $socket;
};

// Reads until $needle has arrived in total, returning the elapsed time since $start.
$readUntil = static function ($socket, string &$received, string $needle, float $start): ?float {
    while (\strpos($received, $needle) === false) {
        $chunk = \fread($socket, 8192);
        if ($chunk === false || $chunk === '') {
            if (\feof($socket)) {
                return null;
            }
            continue;
        }
        $received .= $chunk;
    }

    return \microtime(true) - $start;
};

$readToEof = static function ($socket, string &$received, float $start): float {
    while (!\feof($socket)) {
        $chunk = \fread($socket, 8192);
        if (\is_string($chunk)) {
            $received .= $chunk;
        }
        if (\stream_get_meta_data($socket)['timed_out']) {
            break;
        }
    }

    return \microtime(true) - $start;
};

Server::start();

try {
    echo 'PHP '.\PHP_VERSION.' on '.\PHP_OS_FAMILY."\n";

    // Parts are written in order, with delays between them and a trailing delay before close.
    Server::flush();
    Server::enqueueRawByteParts([
        "HTTP/1.1 200 OK\r\nContent-Length: 10\r\n\r\n",
        'hel',
        'lo',
        1000,
        'world',
        1500,
    ]);
    $start = \microtime(true);
    $socket = $connect();
    $received = '';
    $t1 = $readUntil($socket, $received, 'hello', $start);
    $t2 = $readUntil($socket, $received, 'helloworld', $start);
    $t3 = $readToEof($socket, $received, $start);
    \printf("timings: hello %.3f, world %.3f, eof %.3f\n", $t1 ?? -1, $t2 ?? -1, $t3);
    $check($received === "HTTP/1.1 200 OK\r\nContent-Length: 10\r\n\r\nhelloworld", 'bytes are written verbatim and in order');
    $check($t1 !== null && $t1 < 0.9, 'adjacent byte parts are written without delay');
    $check($t1 !== null && $t2 !== null && $t2 - $t1 >= 0.9 && $t2 - $t1 < 3, 'delay before the next part');
    $check($t2 !== null && $t3 - $t2 >= 1.4 && $t3 - $t2 < 4, 'trailing delay keeps the connection open before close');
    $check(\feof($socket), 'connection closed after the last part');
    \fclose($socket);

    // A leading delay holds back the whole response; an empty string part is allowed.
    Server::enqueueRawByteParts([500, '', "HTTP/1.1 204 No Content\r\n\r\n"]);
    $start = \microtime(true);
    $socket = $connect();
    $received = '';
    $t = $readToEof($socket, $received, $start);
    \printf("leading delay eof %.3f\n", $t);
    $check($received === "HTTP/1.1 204 No Content\r\n\r\n" && $t >= 0.4, 'leading delay and empty part');
    \fclose($socket);

    // The client going away mid-response does not take the server down.
    Server::enqueueRawByteParts(["HTTP/1.1 200 OK\r\nContent-Length: 6\r\n\r\nab", 300, 'cd', 300, 'ef', 300]);
    $socket = $connect();
    $received = '';
    $readUntil($socket, $received, 'ab', \microtime(true));
    \fclose($socket);
    \usleep(1200000);

    // Guzzle reads a delayed body after the early close, proving the server survived.
    Server::enqueueRawByteParts(["HTTP/1.1 200 OK\r\nContent-Length: 6\r\n\r\nab", 200, 'cdef']);
    $client = new Client(['proxy' => '']);
    $response = $client->request('GET', Server::$url.'guzzle', ['stream' => true, 'read_timeout' => 5]);
    $body = (string) $response->getBody();
    $check($body === 'abcdef', 'server survives a client closing early and serves Guzzle afterwards');
    $check(\count(Server::received()) === 4, 'requests are recorded');

    foreach ([-1, 1.5, null, ['x'], true] as $invalid) {
        try {
            Server::enqueueRawByteParts(['ok', $invalid]);
            $check(false, 'rejects '.\var_export($invalid, true));
        } catch (InvalidArgumentException $e) {
            $check(true, 'rejects '.\var_export($invalid, true).': '.$e->getMessage());
        }
    }
} finally {
    Server::stop();
    $log = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.'server.log';
    if (\is_file($log)) {
        echo "--- server.log ---\n".\file_get_contents($log)."\n";
    }
}

echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
