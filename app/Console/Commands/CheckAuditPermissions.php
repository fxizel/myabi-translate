<?php

namespace App\Console\Commands;

use App\Services\AuditGrantPolicy;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CheckAuditPermissions extends Command
{
    protected $signature = 'security:check-audit';

    protected $description = 'Verify append-only audit protection and MariaDB application grants';

    public function handle(AuditGrantPolicy $policy): int
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('This deployment check requires the configured MariaDB application connection.');

            return self::FAILURE;
        }
        $database = DB::getDatabaseName();
        $grants = DB::select('SHOW GRANTS FOR CURRENT_USER()');
        foreach ($grants as $grant) {
            $statement = (string) array_values((array) $grant)[0];
            if (! $policy->allows($statement, $database)) {
                $this->error('The application account has broad, destructive, inherited or unsupported grants. Use a dedicated account with explicit table privileges and SELECT/INSERT only on audit_events.');

                return self::FAILURE;
            }
        }
        $blocked = 0;
        DB::beginTransaction();
        try {
            $event = app(AuditService::class)->record('security.permission_probe', 'audit');
            DB::table('audit_events')->where('id', $event->id)->firstOrFail();
            foreach (['update', 'delete'] as $operation) {
                try {
                    $query = DB::table('audit_events')->where('id', $event->id);
                    $operation === 'update' ? $query->update(['action' => 'security.permission_probe_changed']) : $query->delete();
                } catch (QueryException) {
                    $blocked++;
                }
            }
        } finally {
            DB::rollBack();
        }
        if ($blocked !== 2) {
            $this->error('The database failed to reject audit update and deletion.');

            return self::FAILURE;
        }
        $this->info('Audit SELECT/INSERT succeed; UPDATE/DELETE are rejected; no broad destructive grants were detected.');

        return self::SUCCESS;
    }
}
