<?php

namespace App\Services;

use App\Domain\Devconf\FormatRegistry;
use App\Domain\Devconf\RawExporter;
use App\Models\MyabiVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SimpleExportService
{
    public function __construct(
        private DevconfExportService $exports,
        private StorageBudget $budget,
        private AuditService $audit,
        private OperationLock $lock,
    ) {}

    /** Build a complete private CSV before sending any bytes to the client. */
    public function prepare(User $user, int $versionId, string $type, int $organizationId): array
    {
        abort_unless($user->canManage() && $user->isLoginAllowed(), 403);

        return $this->lock->run(function () use ($user, $versionId, $type, $organizationId) {
            $path = null;
            try {
                return DB::transaction(function () use ($user, $versionId, $type, $organizationId, &$path) {
                    $version = MyabiVersion::findOrFail($versionId);
                    $this->exports->assertCurrentVersion($version);
                    $format = FormatRegistry::get($type);
                    $source = $this->exports->selectSources($versionId, [$type], [$organizationId])[$organizationId][$type];
                    $permissions = new PrivateStoragePermissions;
                    $directory = storage_path('app/private/exports');
                    $permissions->ensureDirectory($directory);
                    $this->removeAbandonedFiles($directory);
                    $this->budget->ensure($source->size * 3 + 10 * 1024 * 1024);
                    $path = $directory.'/'.bin2hex(random_bytes(16)).'.csv';
                    register_shutdown_function(static function () use ($path) {
                        if (is_file($path)) {
                            @unlink($path);
                        }
                    });
                    $coverage = [];
                    $count = 0;
                    $resolver = $this->exports->resolver($source, $format, null, $coverage, $count);
                    $result = (new RawExporter)->export($source->originalPath(), $path, $resolver, $format);
                    $permissions->secureFile($path);
                    if ($result['records'] !== $source->row_count) {
                        throw ValidationException::withMessages(['source' => __('ui.publication_source_count')]);
                    }
                    $this->audit->record('export.downloaded', $source, [], [
                        'version_id' => $versionId, 'type' => $type, 'organization_id' => $organizationId,
                        'source_import_id' => $source->id, 'source_sha256' => $source->sha256,
                        'sha256' => $result['sha256'], 'records' => $result['records'],
                        'format_version' => $result['format_version'], 'coverage' => $coverage,
                    ], $user);

                    return ['path' => $path, 'filename' => $format->filename];
                });
            } catch (Throwable $error) {
                if ($path !== null && is_file($path)) {
                    unlink($path);
                }
                throw $error;
            }
        }, true);
    }

    private function removeAbandonedFiles(string $directory): void
    {
        // A killed worker cannot run its finally/shutdown handler. Leave recent
        // files alone because an earlier request may still be sending its CSV.
        $cutoff = now()->subDay()->timestamp;
        foreach (new \DirectoryIterator($directory) as $file) {
            if (! $file->isLink() && $file->isFile() && preg_match('/^[0-9a-f]{32}\.csv$/D', $file->getFilename())
                && $file->getMTime() < $cutoff) {
                unlink($file->getPathname());
            }
        }
    }
}
