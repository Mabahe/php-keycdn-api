<?php

declare(strict_types=1);

namespace KeyCDN\Tests\Support;

/**
 * Throw-away `php -S` web server running tests/Fixtures/echo-server.php, a fake
 * KeyCDN endpoint that answers every request with the request it received.
 *
 * Has no PHPUnit dependency, so the separate integration projects can reuse it.
 */
final class EchoServer
{
    private function __construct(
        private mixed $process,
        private readonly int $port,
        private readonly string|false $previousNoProxy
    ) {
    }

    /**
     * @throws \RuntimeException if the server could not be started
     */
    public static function start(): self
    {
        // Keep a proxy configured in the environment from intercepting localhost traffic.
        $previousNoProxy = getenv('NO_PROXY');
        putenv('NO_PROXY=127.0.0.1,localhost');

        $port = self::freePort();
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../Fixtures/echo-server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes
        );
        $server = new self($process, $port, $previousNoProxy);

        if (!is_resource($process)) {
            $server->stop();
            throw new \RuntimeException('Could not start the PHP built-in web server.');
        }

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return $server;
            }
            usleep(50_000);
        }

        $server->stop();
        throw new \RuntimeException('The PHP built-in web server did not come up on port ' . $port . '.');
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;

        putenv($this->previousNoProxy === false ? 'NO_PROXY' : 'NO_PROXY=' . $this->previousNoProxy);
    }

    /**
     * A port that is free right now (nothing will be listening on it afterwards).
     *
     * @throws \RuntimeException
     */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException('Could not find a free port: ' . $errstr);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int)substr((string)strrchr((string)$name, ':'), 1);
    }
}
