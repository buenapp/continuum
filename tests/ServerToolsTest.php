<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\ServerTools;

class ServerToolsTest extends TestCase {

    public function testServerToolsRegisters(): void {
        $registry = new ToolRegistry();
        $registry->register(new ServerTools());
        $this->assertTrue($registry->hasTool('server_info'));
        $tools = $registry->listTools();
        $this->assertCount(1, $tools);
        $this->assertSame('server_info', $tools[0]['name']);
    }

    public function testServerInfoShape(): void {
        $info = (new ServerTools())->server_info();
        $this->assertSame('Continuum', $info['name']);
        $this->assertSame(APPLICATION_VERSION, $info['version']);
        $this->assertArrayHasKey('engines', $info);
        $this->assertStringStartsWith('valkey@', $info['engines']['ephemeral']);
        $this->assertStringStartsWith('couchdb@', $info['engines']['durable']);
        $this->assertStringStartsWith('arcadedb@', $info['engines']['structural']);
    }
}
