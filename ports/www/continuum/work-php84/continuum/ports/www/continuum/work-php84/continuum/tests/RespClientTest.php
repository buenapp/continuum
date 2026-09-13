<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\Storage\RespClient;

class RespClientTest extends TestCase {

    /** Holds the fake server end open; a discarded end closes the pipe. @var resource|null */
    private $serverEnd = null;

    /**
     * Pair ends: the client end goes to the RespClient, the server end is held
     * on $this->serverEnd (canned reply preloaded; must stay open or the
     * client's write gets EPIPE).
     */
    private function clientFor(string $cannedReply): RespClient {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], $cannedReply);
        $this->serverEnd = $pair[1];
        return new RespClient('127.0.0.1', 0, 5.0, $pair[0]);
    }

    public function testSimpleString(): void {
        $client = $this->clientFor("+PONG\r\n");
        $this->assertSame('PONG', $client->command('PING'));
    }

    public function testInteger(): void {
        $client = $this->clientFor(":42\r\n");
        $this->assertSame(42, $client->command('INCR', 'counter'));
    }

    public function testBulkString(): void {
        $client = $this->clientFor("\$5\r\nhello\r\n");
        $this->assertSame('hello', $client->command('GET', 'k'));
    }

    public function testNullBulk(): void {
        $client = $this->clientFor("\$-1\r\n");
        $this->assertNull($client->command('GET', 'missing'));
    }

    public function testArrayReply(): void {
        $client = $this->clientFor("*2\r\n\$2\r\nid\r\n\$4\r\nT-1X\r\n");
        $this->assertSame(['id', 'T-1X'], $client->command('LRANGE', 'q', '0', '-1'));
    }

    public function testErrorRaises(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ERR no such key');
        $client = $this->clientFor("-ERR no such key\r\n");
        $client->command('GET', 'k');
    }

    public function testCommandFraming(): void {
        $client = $this->clientFor("+OK\r\n");
        $client->command('SET', 'k', 'v');
        // Client's frame is still sitting in the server end after the reply was consumed.
        stream_set_blocking($this->serverEnd, false);
        $written = stream_get_contents($this->serverEnd);
        $this->assertSame("*3\r\n\$3\r\nSET\r\n\$1\r\nk\r\n\$1\r\nv\r\n", $written);
    }
}
