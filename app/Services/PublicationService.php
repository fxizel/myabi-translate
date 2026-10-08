<?php

namespace App\Services;

use App\Domain\Devconf\FormatRegistry;
use App\Domain\Devconf\RawExporter;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

class PublicationService
{
    public function __construct(private StorageBudget $budget, private AuditService $audit, private OperationLock $lock, private DevconfExportService $exports) {}

    /** Queue only; the cron worker supplies the exclusive heavy-operation lock to build(). */
    public function enqueue(User $user, array $parameters): Publication
    {
        abort_unless($user->canManage(), 403);

        return $this->lock->run(fn () => DB::transaction(function () use ($user, $parameters) {
            $version = MyabiVersion::whereKey($parameters['version_id'])->lockForUpdate()->firstOrFail();
            $this->assertCurrentVersion($version);
            $types = array_values(array_unique($parameters['types'] ?? array_keys(FormatRegistry::all())));
            if ($types === []) {
                $this->block('types', 'publication_types_required');
            }
            foreach ($types as $type) {
                FormatRegistry::get($type);
            }
            $recipients = array_map('intval', $parameters['recipients'] ?? Organisation::where('active', true)->pluck('id')->all());
            $recipients = array_values(array_unique($recipients));
            if ($recipients === [] || Organisation::whereIn('id', $recipients)->where('active', true)->count() !== count($recipients)) {
                $this->block('recipients', 'publication_recipients_required');
            }
            $this->assertSourcesAvailable($version->id, $types, $recipients);
            $sequence = Publication::where('version_id', $version->id)->count() + 1;
            $publication = Publication::create([
                'version_id' => $version->id, 'number' => $version->number.'-'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
                'status' => 'queued', 'types' => $types, 'recipients' => $recipients, 'note' => $parameters['note'] ?? null,
                'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
            $this->audit->record('publication.queued', $publication, [], ['types' => $types, 'recipients' => $recipients], $user);

            return $publication;
        }));
    }

    public function build(Publication $publication): void
    {
        $publication->refresh();
        if (in_array($publication->status, ['published', 'withdrawn'], true)) {
            return;
        }
        if (! in_array($publication->status, ['queued', 'building', 'failed'], true)) {
            throw new RuntimeException('Invalid publication state.');
        }
        $root = storage_path('app/private/publications');
        $permissions = new PrivateStoragePermissions;
        $permissions->ensureDirectory($root);
        $final = $root.'/'.$publication->id;
        $staging = $root.'/.building-'.$publication->id.'-'.bin2hex(random_bytes(8));
        $publication->update(['status' => 'building', 'error' => null]);
        try {
            abort_unless($publication->author?->canManage() && $publication->author->isLoginAllowed(), 403);
            // The exclusive worker lock proves no previous generation is still active.
            // Remove only this publication's abandoned private staging directories.
            foreach (glob($root.'/.building-'.$publication->id.'-*', GLOB_ONLYDIR) ?: [] as $abandoned) {
                $this->removeStaging($abandoned, $root);
            }
            // A crash after the atomic move is recovered from the already frozen files.
            if (is_file($final.'/manifest.json')) {
                $manifest = json_decode(file_get_contents($final.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
                $this->verifyManifest($publication, $manifest);
                $this->makeAvailable($publication, $manifest);

                return;
            }
            if (file_exists($final)) {
                throw new RuntimeException('Incomplete immutable publication directory.');
            }
            $this->assertCurrentVersion($publication->version);
            $sources = $this->exports->selectSources($publication->version_id, $publication->types, $publication->recipients);
            $estimate = array_sum(array_map(fn ($group) => array_sum(array_map(fn ($source) => $source->size * 3 + $source->row_count * 180, $group)), $sources));
            $this->budget->ensure($estimate + 10 * 1024 * 1024);
            $permissions->ensureDirectory($staging);
            // The worker's exclusive lock prevents business writes; this transaction also
            // establishes a coherent database snapshot while manifests are streamed to disk.
            $manifest = DB::transaction(function () use ($publication, $sources, $staging, $permissions) {
                $manifest = [
                    'publication_id' => $publication->id, 'publication' => $publication->number,
                    'version' => $publication->version->number, 'version_id' => $publication->version_id,
                    'application_version' => config('referentiel.version'), 'captured_at' => now()->toIso8601String(),
                    'catalogue_generation' => (int) DB::table('catalogue_state')->where('id', 1)->value('generation'),
                    'types' => $publication->types, 'recipients' => [],
                ];
                foreach ($sources as $organizationId => $group) {
                    $organization = Organisation::findOrFail($organizationId);
                    $directory = $staging.'/'.$organizationId;
                    $permissions->ensureDirectory($directory);
                    $relative = 'publications/'.$publication->id.'/'.$organizationId;
                    $rowManifestPath = $directory.'/revisions.jsonl';
                    $rowsFile = fopen($rowManifestPath, 'xb');
                    if ($rowsFile === false) {
                        throw new RuntimeException('Cannot create revision manifest.');
                    }
                    $recipient = ['organization_id' => $organizationId, 'code' => $organization->code, 'name' => $organization->name, 'files' => []];
                    $rowCount = 0;
                    try {
                        $permissions->secureFile($rowManifestPath);
                        foreach ($group as $type => $source) {
                            $format = FormatRegistry::get($type);
                            $filename = pathinfo($format->filename, PATHINFO_FILENAME).'_'.$this->safeName($publication->number).'_'.$this->safeName($organization->code).'.csv';
                            $coverage = [];
                            $resolver = $this->exports->resolver($source, $format, $rowsFile, $coverage, $rowCount);
                            $result = (new RawExporter)->export($source->originalPath(), $directory.'/'.$filename, $resolver, $format);
                            $permissions->secureFile($directory.'/'.$filename);
                            if ($result['records'] !== $source->row_count) {
                                $this->block('source', 'publication_source_count');
                            }
                            $recipient['files'][] = [...$result, 'type' => $type, 'name' => $filename, 'path' => $relative.'/'.$filename,
                                'source_import_id' => $source->id, 'source_sha256' => $source->sha256, 'coverage' => $coverage];
                        }
                        if (! fflush($rowsFile)) {
                            throw new RuntimeException('Cannot finalize revision manifest.');
                        }
                    } finally {
                        fclose($rowsFile);
                    }
                    $recipient['row_manifest'] = ['name' => 'revisions.jsonl', 'path' => $relative.'/revisions.jsonl', 'records' => $rowCount,
                        'bytes' => filesize($rowManifestPath), 'sha256' => hash_file('sha256', $rowManifestPath)];
                    $catalogue = ['publication' => $manifest['publication'], 'version' => $manifest['version'], 'captured_at' => $manifest['captured_at'],
                        'application_version' => $manifest['application_version'], 'recipient' => $recipient];
                    $this->writeJson($directory.'/catalogue.json', $catalogue);
                    $archiveName = $this->safeName($publication->number).'_'.$this->safeName($organization->code).'.zip';
                    $archivePath = $directory.'/'.$archiveName;
                    $zip = new ZipArchive;
                    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                        throw new RuntimeException('Cannot create publication archive.');
                    }
                    foreach ($recipient['files'] as $file) {
                        if (! $zip->addFile($directory.'/'.$file['name'], $file['name'])) {
                            $zip->close();
                            throw new RuntimeException('Cannot add CSV to publication archive.');
                        }
                    }
                    if (! $zip->addFile($rowManifestPath, 'revisions.jsonl') || ! $zip->addFile($directory.'/catalogue.json', 'catalogue.json') || ! $zip->close()) {
                        throw new RuntimeException('Cannot finalize publication archive.');
                    }
                    $permissions->secureFile($archivePath);
                    $recipient['archive'] = ['name' => $archiveName, 'path' => $relative.'/'.$archiveName, 'bytes' => filesize($archivePath), 'sha256' => hash_file('sha256', $archivePath)];
                    $manifest['recipients'][$organizationId] = $recipient;
                }

                return $manifest;
            });
            $this->writeJson($staging.'/manifest.json', $manifest);
            if (! rename($staging, $final)) {
                throw new RuntimeException('Cannot atomically finalize publication.');
            }
            $this->verifyManifest($publication, $manifest);
            $this->makeAvailable($publication, $manifest);
        } catch (Throwable $error) {
            $message = LocalizedMessage::store($error instanceof ValidationException ? array_merge(...array_values($error->errors())) : 'ui.publication_failed');
            $publication->update(['status' => 'failed', 'error' => $message]);
            $this->audit->record('publication.failed', $publication, [], ['error' => $message], $publication->author);
            $this->removeStaging($staging, $root);
            throw $error;
        }
    }

    /** Check inexpensive prerequisites before queuing; the worker rechecks all source integrity. */
    private function assertSourcesAvailable(int $versionId, array $types, array $recipients): void
    {
        $errors = [];
        foreach ($recipients as $organizationId) {
            foreach ($types as $type) {
                $source = $this->exports->findSource($versionId, $type, $organizationId);
                if (! $source) {
                    $labelKey = 'catalogue.types.'.$type;
                    $label = app('translator')->has($labelKey) ? __($labelKey) : FormatRegistry::get($type)->label;
                    $errors[] = $label.' — '.__('ui.publication_source_missing');
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['source' => array_values(array_unique($errors))]);
        }
    }

    public function assertCurrentVersion(MyabiVersion $version): void
    {
        $this->exports->assertCurrentVersion($version);
    }

    public function withdraw(User $user, Publication $publication, string $reason): void
    {
        abort_unless($user->canManage(), 403);
        if (trim($reason) === '') {
            $this->block('reason', 'reason_required');
        }
        $this->lock->run(fn () => DB::transaction(function () use ($user, $publication, $reason) {
            $publication = Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            if ($publication->status !== 'published') {
                $this->block('publication', 'publication_not_ready');
            }
            $publication->update(['status' => 'withdrawn', 'withdrawal_reason' => $reason, 'updated_by' => $user->id]);
            $this->audit->record('publication.withdrawn', $publication, ['status' => 'published'], ['reason' => $reason], $user);
        }));
    }

    private function makeAvailable(Publication $publication, array $manifest): void
    {
        DB::transaction(function () use ($publication, $manifest) {
            foreach ($manifest['recipients'] as $recipient) {
                $handle = fopen($this->artifactPath($publication, $recipient['row_manifest']['path']), 'rb');
                if ($handle === false) {
                    throw new RuntimeException('Cannot read frozen publication revisions.');
                }
                try {
                    $markers = [];
                    while (($line = fgets($handle)) !== false) {
                        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                        $markers[(int) $row['term_id']] = (int) $row['revision'];
                        if (count($markers) >= config('referentiel.batch_size', 300)) {
                            $this->markPublished($publication, $markers);
                            $markers = [];
                        }
                    }
                    if (! feof($handle)) {
                        throw new RuntimeException('Frozen revision manifest read interrupted.');
                    }
                    if ($markers !== []) {
                        $this->markPublished($publication, $markers);
                    }
                } finally {
                    fclose($handle);
                }
            }
            $publication->update(['status' => 'published', 'manifest' => $manifest, 'error' => null]);
            $this->audit->record('publication.published', $publication, [], ['recipients' => $publication->recipients, 'types' => $publication->types], $publication->author);
        });
    }

    /** Use captured revision numbers even when recovering after a newer validation. */
    private function markPublished(Publication $publication, array $markers): void
    {
        $cases = [];
        $bindings = [$publication->id];
        foreach ($markers as $termId => $revision) {
            if ($termId < 1 || $revision < 1) {
                throw new RuntimeException('Invalid frozen revision reference.');
            }
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = $termId;
            $bindings[] = $revision;
        }
        $ids = array_keys($markers);
        $table = DB::connection()->getQueryGrammar()->wrapTable('terms');
        $sql = 'UPDATE '.$table.' SET last_publication_id = ?, last_published_revision = CASE id '.implode(' ', $cases).' END'
            .' WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).') AND (last_publication_id IS NULL OR last_publication_id <= ?)';
        DB::update($sql, [...$bindings, ...$ids, $publication->id]);
    }

    private function verifyManifest(Publication $publication, array $manifest): void
    {
        if (($manifest['publication_id'] ?? null) !== $publication->id || ($manifest['version_id'] ?? null) !== $publication->version_id
            || count($manifest['recipients'] ?? []) !== count($publication->recipients)) {
            throw new RuntimeException('Invalid immutable publication manifest.');
        }
        foreach ($publication->recipients as $organizationId) {
            $recipient = $manifest['recipients'][$organizationId] ?? null;
            if (! $recipient || count($recipient['files'] ?? []) !== count($publication->types)) {
                throw new RuntimeException('Incomplete immutable publication.');
            }
            foreach ([...$recipient['files'], $recipient['archive'], $recipient['row_manifest']] as $file) {
                $path = $this->artifactPath($publication, $file['path']);
                if (! is_file($path) || filesize($path) !== $file['bytes'] || hash_file('sha256', $path) !== $file['sha256']) {
                    throw new RuntimeException('Immutable publication checksum mismatch.');
                }
            }
        }
    }

    public function artifactPath(Publication $publication, string $relative): string
    {
        $prefix = 'publications/'.$publication->id.'/';
        if (! str_starts_with($relative, $prefix) || str_contains($relative, '..') || str_contains($relative, '\\')) {
            throw new RuntimeException('Invalid publication artifact path.');
        }

        return storage_path('app/private/'.$relative);
    }

    private function writeJson(string $path, array $data): void
    {
        $bytes = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('Cannot write publication metadata.');
        }
        (new PrivateStoragePermissions)->secureFile($path);
    }

    private function safeName(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $value);
    }

    private function removeStaging(string $path, string $root): void
    {
        $resolved = realpath($path);
        $rootPath = realpath($root);
        if (! $resolved || ! $rootPath || ! str_starts_with($resolved, $rootPath.DIRECTORY_SEPARATOR.'.building-')) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($resolved);
    }

    private function block(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __('ui.'.$key)]);
    }
}
