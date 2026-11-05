<?php

namespace Continuum;

/** Canonical board and task scope names. */
final class Scope {

    /** Lowercase a scope after applying the shared segment rules. */
    public static function canonical(?string $scope): string {
        $scope = ($scope === null || $scope === '') ? 'global' : $scope;
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $scope)) {
            throw new \InvalidArgumentException(
                "invalid scope '{$scope}': start with [A-Za-z0-9]; only letters, digits, '.', '_' and '-' allowed"
            );
        }
        return strtolower($scope);
    }

    /** Canonicalize an optional filter; null retains its all-scopes meaning. */
    public static function filter(?string $scope): ?string {
        return $scope === null ? null : self::canonical($scope);
    }
}
