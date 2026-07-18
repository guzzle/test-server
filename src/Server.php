<?php

declare(strict_types=1);

namespace GuzzleHttp\Server;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Psr7;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The Server class is used to control a scripted webserver using node.js that
 * will respond to HTTP requests with queued responses.
 *
 * Queued responses will be served to requests using a FIFO order.  All requests
 * received by the server are stored on the node.js server and can be retrieved
 * by calling {@see Server::received()}.
 *
 * Mock responses that don't require data to be transmitted over HTTP a great
 * for testing.  Mock response, however, cannot test the actual sending of an
 * HTTP request using cURL.  This test server allows the simulation of any
 * number of HTTP request response transactions to test the actual sending of
 * requests over the wire without having to leave an internal network.
 */
final class Server
{
    private static ?Client $client = null;
    /**
     * @var resource|null
     */
    private static $process;
    private static bool $started = false;
    public static string $url = 'http://127.0.0.1:8126/';
    public static int $port = 8126;

    private function __construct()
    {
    }

    public function __serialize(): array
    {
        throw new \LogicException(self::class.' should never be serialized');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException(self::class.' should never be unserialized');
    }

    /**
     * Clear the list of received requests. It does not change the queued
     * responses.
     *
     * @throws \RuntimeException
     */
    public static function flush(): ResponseInterface
    {
        return self::getClient()->request('DELETE', 'guzzle-server/requests');
    }

    /**
     * Queue an array of responses or a single response on the server.
     *
     * When responses are queued, they replace any previously queued responses.
     * As the server receives requests, queued responses are returned in FIFO
     * order. When the queue is empty, the server returns a 500 response and
     * does not record the request.
     *
     * @param array|ResponseInterface $responses A single or array of Responses
     *                                           to queue.
     *
     * @throws InvalidArgumentException
     */
    public static function enqueue(
        #[\SensitiveParameter]
        $responses
    ): void {
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        $data = [];
        foreach ((array) $responses as $response) {
            if (!$response instanceof ResponseInterface) {
                throw new InvalidArgumentException(\sprintf('Invalid response given; got %s.', \get_debug_type($response)));
            }
            $headers = \array_map(static function (array $h): string {
                return \implode(' ,', $h);
            }, $response->getHeaders());

            $data[] = [
                'status' => (string) $response->getStatusCode(),
                'reason' => $response->getReasonPhrase(),
                'headers' => $headers,
                'body' => \base64_encode((string) $response->getBody()),
            ];
        }

        self::getClient()->request('PUT', 'guzzle-server/responses', [
            'json' => $data,
        ]);
    }

    /**
     * Queue a single response manually, for cases where a PSR-7 response is not
     * suitable, such as when you need exact headers or a raw reason phrase.
     *
     * The response is still written through Node's HTTP response handling.
     *
     * @param int|string  $statusCode   Status code for the response, e.g. 200
     * @param string      $reasonPhrase Status reason response e.g "OK"
     * @param array       $headers      Array of headers to send in response
     * @param string|null $body         Body to send in response
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function enqueueRaw(
        $statusCode,
        string $reasonPhrase,
        #[\SensitiveParameter]
        array $headers,
        #[\SensitiveParameter]
        ?string $body
    ): void {
        $data = [
            [
                'status' => (string) $statusCode,
                'reason' => $reasonPhrase,
                'headers' => $headers,
                'body' => \base64_encode((string) $body),
            ],
        ];

        self::getClient()->request('PUT', 'guzzle-server/responses', [
            'json' => $data,
        ]);
    }

    /**
     * Queue a single response as verbatim bytes written straight to the client
     * socket, bypassing Node's HTTP response handling entirely. The connection
     * is closed after the bytes are written. Use this to emulate misbehaving
     * servers that send protocol-invalid bytes, such as one that sends a body
     * in response to a HEAD request.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function enqueueRawBytes(
        #[\SensitiveParameter]
        string $bytes
    ): void {
        self::getClient()->request('PUT', 'guzzle-server/responses', [
            'json' => [
                [
                    'raw' => true,
                    'body' => \base64_encode($bytes),
                ],
            ],
        ]);
    }

    /**
     * Get all of the received requests.
     *
     * Before the server has been started, it returns an empty array.
     *
     * @return RequestInterface[]
     *
     * @throws InvalidArgumentException
     * @throws \JsonException
     * @throws \RuntimeException
     */
    public static function received(): array
    {
        if (!self::$started) {
            return [];
        }

        $response = self::getClient()->request('GET', 'guzzle-server/requests');
        $data = \json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new \RuntimeException(\sprintf('Expected JSON array of received requests from node.js server; got %s', \get_debug_type($data)));
        }

        return \array_map(
            static function (
                #[\SensitiveParameter]
                $message
            ): RequestInterface {
                if (!\is_array($message)) {
                    throw new \RuntimeException('Expected each received request from node.js server to be an array');
                }

                foreach (['http_method', 'uri', 'version'] as $key) {
                    if (!\array_key_exists($key, $message)) {
                        throw new \RuntimeException(\sprintf('Expected received request "%s" from node.js server to be present', $key));
                    }

                    if (!\is_scalar($message[$key])) {
                        throw new \RuntimeException(\sprintf('Expected received request "%s" from node.js server to be a scalar value; got %s', $key, \get_debug_type($message[$key])));
                    }
                }

                if (!\array_key_exists('headers', $message) || !\is_array($message['headers'])) {
                    throw new \RuntimeException('Expected received request "headers" from node.js server to be an array');
                }

                if (!\array_key_exists('body', $message) || (!\is_scalar($message['body']) && null !== $message['body'])) {
                    throw new \RuntimeException('Expected received request "body" from node.js server to be a scalar value or null');
                }

                $uri = (string) $message['uri'];
                if (\array_key_exists('query_string', $message) && null !== $message['query_string']) {
                    if (!\is_scalar($message['query_string'])) {
                        throw new \RuntimeException('Expected received request "query_string" from node.js server to be a scalar value or null');
                    }
                    $uri .= '?'.(string) $message['query_string'];
                }
                $response = new Psr7\Request(
                    (string) $message['http_method'],
                    $uri,
                    $message['headers'],
                    (string) $message['body'],
                    (string) $message['version']
                );
                $uri = $response->getUri()->withScheme('http');
                $host = $response->getHeaderLine('host');
                if ($host !== '') {
                    $parts = \parse_url('http://'.$host);
                    if (\is_array($parts)) {
                        if (isset($parts['host'])) {
                            $uri = $uri->withHost($parts['host']);
                        }
                        if (isset($parts['port'])) {
                            $uri = $uri->withPort($parts['port']);
                        }
                    }
                }

                return $response->withUri($uri);
            },
            $data
        );
    }

    /**
     * Stop running the node.js server
     */
    public static function stop(): void
    {
        try {
            if (self::$started) {
                self::getClient()->request('DELETE', 'guzzle-server');
            }
        } finally {
            self::$started = false;
            self::closeProcess();
        }
    }

    public static function wait(int $maxTries = 5): void
    {
        $tries = 0;
        while (!self::isListening() && ++$tries < $maxTries) {
            \usleep(100000);
        }

        if (!self::isListening()) {
            throw new \RuntimeException('Unable to contact node.js server');
        }
    }

    public static function start(): void
    {
        if (self::$started) {
            return;
        }

        if (!self::isListening()) {
            $port = \filter_var(self::$port, \FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 65535,
                ],
            ]);

            if (false === $port) {
                throw new InvalidArgumentException('Invalid node.js server port');
            }

            $script = __DIR__.\DIRECTORY_SEPARATOR.'server.js';
            $logFile = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.'server.log';

            $process = \proc_open(
                'node '.\escapeshellarg($script).' '.$port,
                [
                    0 => ['pipe', 'r'],
                    1 => ['file', $logFile, 'a'],
                    2 => ['file', $logFile, 'a'],
                ],
                $pipes
            );

            if (!\is_resource($process)) {
                throw new \RuntimeException('Unable to start node.js server');
            }

            self::$process = $process;
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    \fclose($pipe);
                }
            }

            try {
                self::wait(50);
            } catch (\Exception $e) {
                self::closeProcess();

                throw $e;
            }
        }

        self::$started = true;
    }

    private static function isListening(): bool
    {
        try {
            self::getClient()->request('GET', 'guzzle-server/perf', [
                'connect_timeout' => 5,
                'timeout' => 5,
            ]);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function getClient(): Client
    {
        if (null === self::$client) {
            self::$client = new Client([
                'base_uri' => self::$url,
                'proxy' => '',
                'sync' => true,
            ]);
        }

        return self::$client;
    }

    private static function closeProcess(): void
    {
        if (!\is_resource(self::$process)) {
            self::$process = null;

            return;
        }

        $status = \proc_get_status(self::$process);
        if (!empty($status['running'])) {
            \proc_terminate(self::$process);
        }

        \proc_close(self::$process);
        self::$process = null;
    }
}
