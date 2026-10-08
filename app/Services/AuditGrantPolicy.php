<?php

namespace App\Services;

/** Fail-closed grant validation for the dedicated application SQL account. */
class AuditGrantPolicy
{
    public function allows(string $statement, string $database): bool
    {
        if (! preg_match('/^\s*GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+.+$/i', $statement, $parts)
            || preg_match('/\bWITH\s+GRANT\s+OPTION\b/i', $statement)) {
            return false;
        }
        // SHOW GRANTS may quote identifiers and escape '_' or '%' in database grants.
        $scope = preg_replace('/\s+/', '', str_replace('`', '', $parts[2]));
        $scope = strtolower((string) preg_replace('/\\\\(.)/', '$1', $scope));
        $database = strtolower($database);
        $privileges = array_map(fn ($privilege) => strtoupper(trim($privilege)), explode(',', $parts[1]));
        if ($scope === '*.*') {
            return $privileges === ['USAGE'];
        }
        $components = explode('.', $scope);
        if (count($components) !== 2) {
            return false;
        }
        [$schema, $table] = $components;
        if ($table === '*') {
            // Reject write/destructive rights on EVERY schema wildcard, including
            // escaped or pattern grants that may cover the configured database.
            return $schema === $database && array_diff($privileges, ['SELECT', 'INSERT']) === [];
        }
        if ($schema !== $database || $table === '' || strpbrk($table, '%*\\') !== false) {
            return false;
        }
        $allowed = $table === 'audit_events' ? ['SELECT', 'INSERT'] : ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

        return array_diff($privileges, $allowed) === [];
    }
}
