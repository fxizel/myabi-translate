<?php

namespace App\Console\Commands;

use App\Models\BulkOperation;
use App\Models\ImportBatch;
use App\Models\Publication;
use App\Services\FilteredValidationService;
use App\Services\ImportService;
use App\Services\InitialValidationService;
use App\Services\LocalizedMessage;
use App\Services\OperationFailure;
use App\Services\OperationLock;
use App\Services\PublicationService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ProcessWork extends Command
{
    protected $signature = 'referentiel:work {--once : Process one import, publication, or durable validation checkpoint}';

    protected $description = 'Process resumable imports and frozen publications under the shared heavy-operation lock';

    public function handle(OperationLock $lock, ImportService $imports, InitialValidationService $validations, FilteredValidationService $filteredValidations): int
    {
        try {
            return $lock->run(function () use ($imports, $validations, $filteredValidations) {
                do {
                    $import = ImportBatch::whereIn('status', ['queued', 'analyzing', 'apply_queued', 'applying'])->oldest('id')->first();
                    $publication = $import ? null : Publication::whereIn('status', ['queued', 'building'])->oldest('id')->first();
                    $validation = ($import || $publication) ? null : BulkOperation::whereIn('status', ['queued', 'processing'])->oldest('id')->first();
                    if (! $import && ! $publication && ! $validation) {
                        return self::SUCCESS;
                    }
                    try {
                        if ($import) {
                            if (in_array($import->status, ['apply_queued', 'applying'])) {
                                $imports->apply($import);
                            } else {
                                $imports->analyze($import);
                            }
                            $this->line('Import '.$import->id.': '.$import->fresh()->status);
                        } elseif ($publication) {
                            app(PublicationService::class)->build($publication);
                        } else {
                            ($validation->type === 'filtered_validation' ? $filteredValidations : $validations)->process($validation);
                            if ($validation->fresh()->status === 'failed') {
                                $this->error('Validation '.$validation->id.' failed.');

                                return self::FAILURE;
                            }
                        }
                    } catch (\Throwable $e) {
                        $item = $import ?? $publication ?? $validation;
                        $message = $e instanceof ValidationException ? LocalizedMessage::store(array_merge(...array_values($e->errors())))
                            : OperationFailure::capture($e, $item);
                        $item->update(['status' => 'failed', 'error' => $message]);
                        $this->error('Operation '.$item->id.' failed.');

                        return self::FAILURE;
                    }
                } while (! $this->option('once'));

                return self::SUCCESS;
            }, true);
        } catch (ValidationException) {
            $this->line('Another operation holds the lock.');

            return self::SUCCESS;
        }
    }
}
