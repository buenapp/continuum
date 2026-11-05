<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\Scope;

class ScopeTest extends TestCase {

    public function testCanonicalizesAsciiCaseAndDefaults(): void {
        $this->assertSame('heliofane', Scope::canonical('HELIOFANE'));
        $this->assertSame('heliofane', Scope::canonical('Heliofane'));
        $this->assertSame('project.one-2_test', Scope::canonical('Project.One-2_Test'));
        $this->assertSame('global', Scope::canonical(null));
        $this->assertSame('global', Scope::canonical(''));
    }

    public function testNullableFilterRetainsAllScopesSentinel(): void {
        $this->assertNull(Scope::filter(null));
        $this->assertSame('heliofane', Scope::filter('HELIOFANE'));
        $this->assertSame('global', Scope::filter(''));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidScopes')]
    public function testRejectsInvalidScope(string $scope): void {
        $this->expectException(\InvalidArgumentException::class);
        Scope::canonical($scope);
    }

    public static function invalidScopes(): array {
        return [['_bad'], ['has/slash'], ['has space'], ['éclair']];
    }
}
