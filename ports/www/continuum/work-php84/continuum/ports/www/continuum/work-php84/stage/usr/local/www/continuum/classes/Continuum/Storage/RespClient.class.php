<?php

namespace Continuum\Storage;

/**
 * Minimal RESP2 client for ValKey (Redis-compatible) servers.
 *
 * Synchronous, one command at a time over a persistent stream. No pipelining
 * dependency management — callers batch by issuing sequential commands.
 */
class RespClient {

    /** @var resource|null */
    private $stream = null;

    private string $host;
    private int $port;
    private float $timeout;

    /**
     * @param resource|null $stream Pre-opened stream (tests); when null a
     *                              connection to $host:$port is opened lazily.
     */
    public function __construct(string $host = '127.0.0.1', int $port = 6379, float $timeout = 2.0, $stream = null) {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->stream = $stream;
    }

    /**
     * Issue a RESP command and return the parsed reply.
     *
     * @return mixed string|int|null|array reply
     * @throws \RuntimeException on connection/protocol errors or -ERR replies
     */
    public function command(string ...$args): mixed {
        $this->connect();
        $out = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $out .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        if (fwrite($this->stream, $out) === false) {
            throw new \RuntimeException("RESP write failed: {$this->host}:{$this->port}");
        }
        return $this->readReply();
    }

    private function connect(): void {
        if ($this->stream !== null) { return; }
        $errno = 0; $errstr = '';
        $this->stream = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if ($this->stream === false) {
            $this->stream = null;
            throw new \RuntimeException("RESP connect failed: {$this->host}:{$this->port} ($errno) $errstr");
        }
        stream_set_timeout($this->stream, (int)$this->timeout, (int)(($this->timeout - (int)$this->timeout) * 1e6));
    }

    private function readReply(): mixed {
        $line = $this->readLine();
        if ($line === null) { throw new \RuntimeException('RESP read failed (connection closed)'); }
        $type = $line[0];
        $payload = substr($line, 1);
        switch ($type) {
            case '+': return $payload;
            case '-': throw new \RuntimeException("RESP error: $payload");
            case ':': return (int)$payload;
            case '$':
                $len = (int)$payload;
                if ($len < 0) { return null; }
                return $this->readBytes($len);
            case '*':
                $count = (int)$payload;
                if ($count < 0) { return null; }
                $reply = [];
                for ($i = 0; $i < $count; $i++) { $reply[] = $this->readReply(); }
                return $reply;
            default:
                throw new \RuntimeException("RESP protocol error: unexpected type byte '$type'");
        }
    }

    private function readLine(): ?string {
        $line = fgets($this->stream);
        if ($line === false) { return null; }
        return rtrim($line, "\r\n");
    }

    private function readBytes(int $len): string {
        $data = '';
        while (strlen($data) < $len + 2) { // payload + trailing CRLF
            $chunk = fread($this->stream, $len + 2 - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('RESP read failed mid-bulk-string');
            }
            $data .= $chunk;
        }
        return substr($data, 0, $len);
    }

    public function close(): void {
        if ($this->stream !== null) { fclose($this->stream); $this->stream = null; }
    }
}
