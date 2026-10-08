<?php

namespace Tests\Unit;

use App\Services\AuditGrantPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SecurityAuditGrantPolicyTest extends TestCase
{
    #[DataProvider('grants')]
    public function test_effective_grant_scopes_are_normalized_and_fail_closed(string $grant, bool $expected): void
    {
        $this->assertSame($expected, (new AuditGrantPolicy)->allows($grant, 'myabi_test'));
    }

    public static function grants(): array
    {
        return [
            'dedicated account usage' => ['GRANT USAGE ON *.* TO `app`@`%`', true],
            'audit insert read only' => ['GRANT SELECT, INSERT ON `myabi_test`.`audit_events` TO `app`@`%`', true],
            'other table normal writes' => ['GRANT SELECT, INSERT, UPDATE, DELETE ON `myabi_test`.`users` TO `app`@`%`', true],
            'escaped database exact scope' => ['GRANT SELECT, INSERT ON `myabi\\_test`.`audit_events` TO `app`@`%`', true],
            'case and whitespace' => ['grant select, insert on `MYABI_TEST` . `AUDIT_EVENTS` to `app`@`%`', true],
            'audit update' => ['GRANT SELECT, INSERT, UPDATE ON `myabi_test`.`audit_events` TO `app`@`%`', false],
            'escaped audit update' => ['GRANT UPDATE ON `myabi\\_test`.`audit_events` TO `app`@`%`', false],
            'escaped broad schema update' => ['GRANT SELECT, INSERT, UPDATE, DELETE ON `myabi\\_test`.* TO `app`@`%`', false],
            'wildcard broad schema update' => ['GRANT UPDATE ON `myabi%`.* TO `app`@`%`', false],
            'underscore wildcard delete' => ['GRANT DELETE ON `myabi_`.* TO `app`@`%`', false],
            'broad other schema rejected' => ['GRANT UPDATE ON `another_database`.* TO `app`@`%`', false],
            'broad alter' => ['GRANT ALTER ON `myabi\\_test`.* TO `app`@`%`', false],
            'broad trigger' => ['GRANT TRIGGER ON `myabi\\_test`.* TO `app`@`%`', false],
            'global all' => ['GRANT ALL PRIVILEGES ON *.* TO `app`@`%`', false],
            'global inserts can reach privilege tables' => ['GRANT INSERT ON *.* TO `app`@`%`', false],
            'global super' => ['GRANT SUPER ON *.* TO `app`@`%`', false],
            'global file' => ['GRANT FILE ON *.* TO `app`@`%`', false],
            'grant delegation' => ['GRANT SELECT, INSERT ON `myabi_test`.`audit_events` TO `app`@`%` WITH GRANT OPTION', false],
            'column specific audit update' => ['GRANT UPDATE (`action`) ON `myabi_test`.`audit_events` TO `app`@`%`', false],
            'unexpected routine capability' => ['GRANT EXECUTE ON PROCEDURE `myabi_test`.`rewrite_audit` TO `app`@`%`', false],
            'inherited role must not be certified' => ['GRANT `administrators` TO `app`@`%`', false],
            'unrecognized statement' => ['SET DEFAULT ROLE `admin` FOR `app`@`%`', false],
        ];
    }
}
