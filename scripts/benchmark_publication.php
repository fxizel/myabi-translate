<?php

declare(strict_types=1);

use App\Domain\Devconf\CsvReader;
use App\Domain\Devconf\FormatRegistry;
use App\Domain\Devconf\TranslationValidator;
use App\Http\Controllers\PublicationController;
use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Term;
use App\Models\User;
use App\Services\AuditService;
use App\Services\OperationLock;
use App\Services\PublicationService;
use App\Services\StorageBudget;
use App\Services\WorkflowService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Local acceptance against institutional data. Stdout contains metrics only.
// Read-only preparation: php scripts/benchmark_publication.php
// Reserved local database only: php scripts/benchmark_publication.php --execute
// Validates 100 French mRic proposals, publishes to ARGE and VD, then changes one value.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('local', 'testing')) {
    fwrite(STDERR, "This acceptance scenario is restricted to local/test environments.\n");
    exit(2);
}

function requireAcceptance(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function writeAcceptance(array $report): string
{
    $directory = base_path('.runtime');
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $path = $directory.'/publication-acceptance-'.gmdate('Ymd-His').'.json';
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);

    return $path;
}

$stage = 'prepare';
$started = microtime(true);
$result = ['started_at' => gmdate(DATE_ATOM), 'database' => DB::connection()->getDatabaseName()];
try {
    DB::disableQueryLog();
    $source = ImportBatch::where('type', 'mric')->where('source_kind', 'publisher')->where('status', 'applied')
        ->orderByDesc('catalogue_generation')->latest('id')->firstOrFail();
    $manager = User::where('email', 'benchmark@referentiel.invalid')->firstOrFail();
    requireAcceptance($manager->canManage() && $manager->isLoginAllowed(), 'An active local benchmark manager is required.');
    $root = Organisation::where('is_root', true)->where('active', true)->firstOrFail();
    $selection = [];
    $termIds = [];
    $candidates = Proposal::with('term.sourceImport')->where('language', 'fr')->where('attribute', 'label')->where('status', 'pending')
        ->whereHas('term', fn ($query) => $query->where('type', 'mric')->whereNull('organization_id')->where('scope_unconfirmed', false)->where('obsolete', false))
        ->whereExists(fn ($query) => $query->selectRaw('1')->from('import_rows')->whereColumn('import_rows.term_id', 'proposals.term_id')->where('import_rows.import_id', $source->id))
        ->orderBy('id');
    foreach ($candidates->lazyById(300) as $proposal) {
        if (isset($termIds[$proposal->term_id]) || isset($proposal->term->validated['label']['fr']) || $proposal->author_id === $manager->id) {
            continue;
        }
        $attribute = $proposal->term->attributesForDisplay()['label'];
        if (TranslationValidator::validate($attribute['reference'], $proposal->value)['errors'] !== []) {
            continue;
        }
        $selection[$proposal->id] = $proposal->lock_version;
        $termIds[$proposal->term_id] = true;
        if (count($selection) === 100) {
            break;
        }
    }
    requireAcceptance(count($selection) === 100, 'The scenario requires 100 distinct eligible untranslated mRic terms.');
    $result += ['source_import_id' => $source->id, 'source_records' => $source->row_count, 'selected_validations' => count($selection)];
    echo json_encode(['stage' => 'prepared', ...$result, 'execute' => in_array('--execute', $argv, true)], JSON_THROW_ON_ERROR).PHP_EOL;
    if (! in_array('--execute', $argv, true)) {
        exit(0);
    }

    $stage = 'validation';
    $vd = Organisation::firstOrCreate(['code' => 'VD'], ['name' => 'Vaud — recette locale', 'active' => true, 'created_by' => $manager->id, 'updated_by' => $manager->id]);
    requireAcceptance($vd->active && $vd->id !== $root->id, 'Two distinct active recipient organizations are required.');
    if ($vd->wasRecentlyCreated) {
        app(AuditService::class)->record('organization.created', $vd, [], ['code' => $vd->code, 'local_acceptance' => true], $manager);
    }
    $workflow = app(WorkflowService::class);
    $workflow->bulk($manager, $selection, 'validate', null, false);
    $frozenTerms = Term::whereIn('id', array_keys($termIds))->get()->keyBy('id');
    $validatedAt = microtime(true);
    echo json_encode(['stage' => 'validated', 'validations' => count($selection)], JSON_THROW_ON_ERROR).PHP_EOL;

    $stage = 'publication';
    $service = app(PublicationService::class);
    $publication = $service->enqueue($manager, ['version_id' => $source->version_id, 'types' => ['mric'], 'recipients' => [(int) $root->id, (int) $vd->id],
        'note' => 'Local acceptance scenario: 100 validations, two recipients, frozen publication and later change.']);
    $result['publication_id'] = $publication->id;
    app(OperationLock::class)->run(fn () => $service->build($publication), true);
    $publication->refresh();
    requireAcceptance($publication->status === 'published', 'Publication did not become available.');
    $publishedAt = microtime(true);
    echo json_encode(['stage' => 'published', 'publication_id' => $publication->id, 'recipients' => count($publication->recipients)], JSON_THROW_ON_ERROR).PHP_EOL;

    $stage = 'archive_verification';
    $filesBefore = [];
    $recipientResults = [];
    foreach ($publication->manifest['recipients'] as $organizationId => $recipient) {
        $archive = $recipient['archive'];
        $csv = $recipient['files'][0];
        $archivePath = $service->artifactPath($publication, $archive['path']);
        $csvPath = $service->artifactPath($publication, $csv['path']);
        foreach ([$archive, $csv, $recipient['row_manifest']] as $file) {
            $path = $service->artifactPath($publication, $file['path']);
            $filesBefore[$path] = hash_file('sha256', $path);
            requireAcceptance($filesBefore[$path] === $file['sha256'] && filesize($path) === $file['bytes'], 'Frozen file integrity mismatch.');
        }
        $selectedRecords = [];
        $manifestRecords = 0;
        $manifestFile = fopen($service->artifactPath($publication, $recipient['row_manifest']['path']), 'rb');
        while (($line = fgets($manifestFile)) !== false) {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $manifestRecords++;
            if (isset($termIds[$row['term_id']])) {
                requireAcceptance($row['revision'] === $frozenTerms[$row['term_id']]->revision_no, 'Captured revision mismatch.');
                $selectedRecords[$row['record']] = $row['term_id'];
            }
        }
        fclose($manifestFile);
        requireAcceptance(count($selectedRecords) === 100, 'The frozen manifest did not capture every selected term.');

        $recipientSource = ImportBatch::findOrFail($csv['source_import_id']);
        $format = FormatRegistry::get('mric');
        $frColumn = array_search('fr_CH', $format->headers, true);
        requireAcceptance($frColumn !== false, 'French native column is missing.');
        $originalRecords = (new CsvReader)->records($recipientSource->originalPath());
        $records = 0;
        $selectedValues = 0;
        foreach ((new CsvReader)->records($csvPath) as $record) {
            requireAcceptance($originalRecords->valid(), 'Export has more records than its source.');
            $original = $originalRecords->current();
            requireAcceptance($record->number === $original->number && $record->ending === $original->ending, 'Native record order or line endings changed.');
            if ($record->number === 0) {
                $format->validateHeader($record->values);
                requireAcceptance($record->raw() === $original->raw(), 'Native header changed.');
            } else {
                $records++;
                foreach ($original->rawCells as $index => $raw) {
                    requireAcceptance($index === $frColumn || $record->rawCells[$index] === $raw, 'A technical or non-French source cell changed.');
                }
                if (isset($selectedRecords[$record->number])) {
                    $expected = $frozenTerms[$selectedRecords[$record->number]]->validated['label']['fr'];
                    requireAcceptance($record->values[$frColumn] === $expected, 'A selected validated value is absent from the export.');
                    $selectedValues++;
                }
            }
            $originalRecords->next();
        }
        requireAcceptance(! $originalRecords->valid(), 'Export has fewer records than its source.');
        requireAcceptance($records === $recipientSource->row_count && $manifestRecords === $records && $selectedValues === 100, 'Frozen record counts differ.');
        $zip = new ZipArchive;
        requireAcceptance($zip->open($archivePath) === true, 'Archive cannot be opened.');
        foreach ([$csv, $recipient['row_manifest']] as $file) {
            $stream = $zip->getStream($file['name']);
            requireAcceptance(is_resource($stream), 'Archive member is missing.');
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            fclose($stream);
            requireAcceptance(hash_final($hash) === $file['sha256'], 'Archive member checksum differs.');
        }
        requireAcceptance($zip->numFiles === 3 && $zip->locateName('catalogue.json') !== false, 'Archive contents are incomplete.');
        $zip->close();
        $recipientResults[] = ['organization_id' => (int) $organizationId, 'source_import_id' => $recipientSource->id, 'records' => $records,
            'captured_terms' => count($selectedRecords), 'validated_values_verified' => $selectedValues,
            'csv_sha256' => $csv['sha256'], 'archive_sha256' => $archive['sha256'], 'archive_bytes' => $archive['bytes'],
            'technical_cells_and_line_endings_preserved' => true, 'archive_members_verified' => true];
    }

    $stage = 'immutability';
    $term = Term::findOrFail(array_key_first($termIds));
    $priorRevision = $term->revision_no;
    $changed = $workflow->propose($manager, $term, 'label', 'fr', $term->validated['label']['fr'].' — contrôle local', $term->lock_version);
    // Explicit manager exception is part of this local scenario and is audited.
    $workflow->decide($manager, $changed, 'validate', $changed->fresh()->lock_version, null, true);
    requireAcceptance($term->fresh()->revision_no > $priorRevision, 'Later validation did not create a revision.');
    foreach ($filesBefore as $path => $hash) {
        requireAcceptance(hash_file('sha256', $path) === $hash, 'Later validation changed a frozen file.');
    }

    $stage = 'download_acl';
    $controller = app(PublicationController::class);
    $acl = [];
    $requestFor = static function (User $actor, int $organizationId) use ($publication): Request {
        $request = Request::create('/publications/'.$publication->id.'/download', 'GET', ['organization_id' => $organizationId]);
        $request->setUserResolver(static fn () => $actor);

        return $request;
    };
    $expectForbidden = static function (User $actor, int $organizationId) use ($controller, $publication, $requestFor): bool {
        try {
            $controller->download($requestFor($actor, $organizationId), $publication);
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode() === 403;
        }

        return false;
    };
    // Temporary reader accounts and their test audit events leave no persisted fixtures.
    DB::beginTransaction();
    try {
        $auditBefore = AuditEvent::where('action', 'publication.downloaded')->count();
        foreach ([$root, $vd] as $organization) {
            $reader = User::create(['name' => 'Local acceptance reader', 'email' => 'publication-check-'.bin2hex(random_bytes(6)).'@referentiel.invalid',
                'password' => bin2hex(random_bytes(32)), 'organization_id' => $organization->id, 'roles' => ['reader' => ['de', 'fr', 'it', 'en']],
                'active' => true, 'notifications_enabled' => false]);
            $response = $controller->download($requestFor($reader, $organization->id), $publication);
            $expected = $publication->manifest['recipients'][$organization->id]['archive'];
            $acl['reader_'.$organization->id.'_own_archive'] = $response instanceof BinaryFileResponse
                && $response->getStatusCode() === 200
                && hash_file('sha256', $response->getFile()->getPathname()) === $expected['sha256']
                && $response->headers->get('Content-Type') === 'application/zip';
            $otherId = $organization->id === $root->id ? $vd->id : $root->id;
            $acl['reader_'.$organization->id.'_other_archive_denied'] = $expectForbidden($reader, $otherId);
            $reader->active = false;
            $acl['reader_'.$organization->id.'_inactive_denied'] = $expectForbidden($reader, $organization->id);
            $reader->active = true;
            $reader->is_technical = true;
            $acl['reader_'.$organization->id.'_technical_denied'] = $expectForbidden($reader, $organization->id);
        }
        $outsider = new User(['name' => 'Local outsider', 'active' => true, 'organization_id' => null, 'roles' => ['reader' => ['fr']]]);
        $acl['nonrecipient_denied'] = $expectForbidden($outsider, $root->id);
        foreach ([$root, $vd] as $organization) {
            $response = $controller->download($requestFor($manager, $organization->id), $publication);
            $acl['manager_'.$organization->id.'_archive'] = $response instanceof BinaryFileResponse && $response->getStatusCode() === 200;
        }
        $acl['authorized_downloads_audited'] = AuditEvent::where('action', 'publication.downloaded')->count() === $auditBefore + 4;
        requireAcceptance(! in_array(false, $acl, true), 'Download access control check failed.');
    } finally {
        DB::rollBack();
    }

    $result += ['stage' => 'complete', 'validations' => 100, 'recipients' => $recipientResults, 'download_acl' => $acl,
        'later_change_preserves_frozen_files' => true, 'validation_seconds' => round($validatedAt - $started, 3),
        'publication_seconds' => round($publishedAt - $validatedAt, 3), 'total_seconds' => round(microtime(true) - $started, 3),
        'peak_memory_bytes' => memory_get_peak_usage(true), 'storage' => app(StorageBudget::class)->usage(), 'passed' => true];
    $report = writeAcceptance($result);
    echo json_encode([...$result, 'private_report' => basename($report)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    $result += ['stage' => $stage, 'passed' => false, 'error_type' => $exception::class, 'error_message' => $exception->getMessage(),
        'validation_errors' => $exception instanceof ValidationException ? $exception->errors() : [],
        'total_seconds' => round(microtime(true) - $started, 3), 'peak_memory_bytes' => memory_get_peak_usage(true)];
    $report = writeAcceptance($result);
    // Details may contain business identifiers and remain only in the ignored private report.
    fwrite(STDERR, json_encode(['stage' => $stage, 'passed' => false, 'error_type' => $exception::class, 'private_report' => basename($report)], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
