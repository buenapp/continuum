<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\IdentityResources;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class IdentityResourcesTest extends TestCase {

    private const PUBKEY = 'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=';
    private const PUBKEY2 = 'AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI=';

    private function storage(FakeCouch $couch): ContinuumStorage {
        return new ContinuumStorage(new ValKeyStore(new FakeRespClient([])), $couch, new FakeArcade());
    }

    private function registry(FakeCouch $couch): ToolRegistry {
        $registry = new ToolRegistry();
        $registry->register(new IdentityResources($this->storage($couch)));
        return $registry;
    }

    /** The record identity-admin writes on register (contract shape). */
    private function registered(): array {
        return [
            'pubkey' => self::PUBKEY,
            'status' => 'active',
            'created_at' => '2026-10-20T00:00:00+00:00',
            'rotated_at' => null,
            'revoked_at' => null,
        ];
    }

    private function devinDoc(): object {
        return (object)[
            '_id' => 'devin', '_rev' => '3-abc',
            'pubkey' => self::PUBKEY,
            'status' => 'active',
            'created_at' => '2026-10-20T00:00:00+00:00',
            'rotated_at' => null,
            'revoked_at' => null,
        ];
    }

    // --- registry writes, replaying the identity-admin workflows

    public function testRegisterWritesContractRecord(): void {
        $couch = new FakeCouch([['code' => 201, 'body' => ['id' => 'devin', 'rev' => '1-a']]]);
        $wrote = $this->storage($couch)->saveIdentity('devin', $this->registered());
        $this->assertSame('continuum_identities/devin', $couch->calls[0]['path']);
        $this->assertSame('PUT', $couch->calls[0]['verb']);
        $this->assertSame($this->registered(), $couch->calls[0]['data']);
        $this->assertSame(['id' => 'devin', 'rev' => '1-a'], $wrote);
    }

    public function testRegisterTwiceRefused(): void {
        $couch = new FakeCouch([
            ['code' => 201, 'body' => ['id' => 'devin', 'rev' => '1-a']],
            ['code' => 409, 'body' => ['error' => 'conflict']],
        ]);
        $storage = $this->storage($couch);
        $storage->saveIdentity('devin', $this->registered());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CouchDB revision conflict on identities/devin');
        $storage->saveIdentity('devin', $this->registered());
    }

    public function testRotatePreservesCreatedAndSetsRotated(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => $this->devinDoc()],
            ['code' => 201, 'body' => ['id' => 'devin', 'rev' => '4-def']],
        ]);
        $storage = $this->storage($couch);
        $existing = $storage->loadIdentity('devin');
        $storage->saveIdentity('devin', [
            'pubkey' => self::PUBKEY2,
            'status' => 'active',
            'created_at' => $existing['created_at'],
            'rotated_at' => '2026-10-21T00:00:00+00:00',
            'revoked_at' => null,
        ], $existing['_rev'] ?? null);
        $this->assertSame('continuum_identities/devin', $couch->calls[1]['path']);
        $this->assertSame([
            'pubkey' => self::PUBKEY2,
            'status' => 'active',
            'created_at' => '2026-10-20T00:00:00+00:00',
            'rotated_at' => '2026-10-21T00:00:00+00:00',
            'revoked_at' => null,
            '_rev' => '3-abc',
        ], $couch->calls[1]['data']);
    }

    public function testRevokeKeepsRecord(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => $this->devinDoc()],
            ['code' => 201, 'body' => ['id' => 'devin', 'rev' => '4-def']],
        ]);
        $storage = $this->storage($couch);
        $existing = $storage->loadIdentity('devin');
        $storage->saveIdentity('devin', [
            'pubkey' => $existing['pubkey'],
            'status' => 'revoked',
            'created_at' => $existing['created_at'],
            'rotated_at' => $existing['rotated_at'],
            'revoked_at' => '2026-10-21T00:00:00+00:00',
        ], $existing['_rev'] ?? null);
        $this->assertSame(['GET', 'PUT'], array_column($couch->calls, 'verb'));
        $this->assertSame(self::PUBKEY, $couch->calls[1]['data']['pubkey']);
        $this->assertSame('revoked', $couch->calls[1]['data']['status']);
        $this->assertSame('2026-10-21T00:00:00+00:00', $couch->calls[1]['data']['revoked_at']);
    }

    public function testListIdentitiesReturnsWorkstreams(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => ['rows' => [
            (object)['id' => 'continuum', 'doc' => (object)[
                'pubkey' => self::PUBKEY2, 'status' => 'active',
                'created_at' => '2026-10-20T02:00:00+00:00',
                'rotated_at' => null,
                'revoked_at' => null,
            ]],
            (object)['id' => 'devin', 'doc' => $this->devinDoc()],
        ]]]]);
        $docs = $this->storage($couch)->listIdentities();
        $this->assertSame(['continuum', 'devin'], array_keys($docs));
        $this->assertSame(self::PUBKEY, $docs['devin']['pubkey']);
    }

    // --- resource reads

    public function testRegistersStaticsAndTemplates(): void {
        $registry = $this->registry(new FakeCouch([]));
        $this->assertSame(['continuum://identities'], array_column($registry->listResources(), 'uri'));
        $this->assertContains('continuum://identities/{workstream}', array_column($registry->listResourceTemplates(), 'uriTemplate'));
    }

    public function testRegistryListsActiveAndRevoked(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => ['rows' => [
            (object)['id' => 'devin', 'doc' => (object)[
                'pubkey' => self::PUBKEY, 'status' => 'revoked',
                'created_at' => '2026-10-20T00:00:00+00:00',
                'rotated_at' => '2026-10-21T00:00:00+00:00',
                'revoked_at' => '2026-10-21T01:00:00+00:00',
            ]],
            (object)['id' => 'continuum', 'doc' => (object)[
                'pubkey' => self::PUBKEY2, 'status' => 'active',
                'created_at' => '2026-10-20T02:00:00+00:00',
                'rotated_at' => null,
                'revoked_at' => null,
            ]],
        ]]]]);
        $content = $this->registry($couch)->readResource('continuum://identities');
        $body = json_decode($content['text'], true);
        $this->assertSame(2, $body['count']);
        $this->assertSame(['continuum', 'devin'], array_column($body['identities'], 'workstream'));
        $this->assertSame([
            'workstream' => 'devin',
            'pubkey' => self::PUBKEY,
            'status' => 'revoked',
            'created_at' => '2026-10-20T00:00:00+00:00',
            'rotated_at' => '2026-10-21T00:00:00+00:00',
            'revoked_at' => '2026-10-21T01:00:00+00:00',
        ], $body['identities'][1]);
        $this->assertSame('2026-10-21T01:00:00+00:00', $content['annotations']['lastModified']);
    }

    public function testIdentityRecordForOneWorkstream(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => $this->devinDoc()]]);
        $content = $this->registry($couch)->readResource('continuum://identities/devin');
        $body = json_decode($content['text'], true);
        $this->assertSame([
            'workstream' => 'devin',
            'pubkey' => self::PUBKEY,
            'status' => 'active',
            'created_at' => '2026-10-20T00:00:00+00:00',
            'rotated_at' => null,
            'revoked_at' => null,
        ], $body);
        $this->assertSame('2026-10-20T00:00:00+00:00', $content['annotations']['lastModified']);
    }

    public function testIdentityRecordAbsentThrows(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('identity ghost is not registered');
        $this->registry(new FakeCouch([['code' => 404, 'body' => (object)['error' => 'not_found']]]))
            ->readResource('continuum://identities/ghost');
    }
}
