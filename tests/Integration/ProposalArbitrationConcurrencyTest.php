<?php

namespace Tests\Integration;

use App\Models\Proposal;
use App\Models\User;
use App\Services\SourceRecord;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\DB;
use Tests\Support\BrowserExtensionConcurrencyFixture as Fixture;
use Tests\Support\DatabaseProcess;
use Tests\Support\ProposalScreenshotSource;
use Tests\TestCase;

class ProposalArbitrationConcurrencyTest extends TestCase
{
    public function test_validation_rechecks_an_arbitration_committed_while_waiting_for_the_cell(): void
    {
        if (getenv('MYABI_ISOLATED_TESTS') !== '1' || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires isolated MariaDB row locks and committed connections.');
        }
        Fixture::assertIsolated();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        app()->instance(SourceRecord::class, new ProposalScreenshotSource);
        $author = User::factory()->create(['roles' => ['translator' => ['fr']]]);
        $validator = User::factory()->create(['roles' => ['validator' => ['fr']]]);
        $term = Fixture::term('Synthetic arbitration', $author->id);
        $workflow = app(WorkflowService::class);
        $preferred = $workflow->propose($author, $term, 'label', 'fr', 'First synthetic value');
        $alternative = $workflow->propose($author, $term, 'label', 'fr', 'Second synthetic value');
        $release = getenv('MYABI_CONCURRENCY_DIRECTORY').'/arbitration-'.bin2hex(random_bytes(6));

        $arbitration = DatabaseProcess::start(<<<'PHP'
        \Tests\Support\BrowserExtensionConcurrencyFixture::assertIsolated();
        \Illuminate\Support\Facades\DB::listen(function ($query) use ($parameters) {
            if (str_starts_with($query->sql, 'update `proposals`') && in_array('arbitration', $query->bindings, true)) {
                echo "ARBITRATION_WRITTEN\n"; flush();
                \Tests\Support\BrowserExtensionConcurrencyFixture::wait($parameters['release']);
            }
        });
        app(\App\Services\WorkflowService::class)->decide(\App\Models\User::findOrFail($parameters['user']),
            \App\Models\Proposal::findOrFail($parameters['proposal']), 'arbitrate', 1);
        PHP, ['user' => $author->id, 'proposal' => $alternative->id, 'release' => $release]);

        $validation = null;
        try {
            DatabaseProcess::awaitOutput($arbitration, 'ARBITRATION_WRITTEN');
            $validation = DatabaseProcess::start(<<<'PHP'
            \Tests\Support\BrowserExtensionConcurrencyFixture::assertIsolated();
            \Illuminate\Support\Facades\DB::connection()->beforeExecuting(function ($sql) {
                if (str_contains($sql, 'from `terms`') && str_ends_with($sql, 'for update')) {
                    echo "VALIDATION_WAITING\n"; flush();
                }
            });
            try {
                app(\App\Services\WorkflowService::class)->decide(\App\Models\User::findOrFail($parameters['user']),
                    \App\Models\Proposal::findOrFail($parameters['proposal']), 'validate', 1);
                echo "VALIDATED\n";
            } catch (\Illuminate\Validation\ValidationException $error) {
                echo isset($error->errors()['decision']) ? "COMPETITOR_PROTECTED\n" : "UNEXPECTED_ERROR\n";
            }
            PHP, ['user' => $validator->id, 'proposal' => $preferred->id]);
            DatabaseProcess::awaitOutput($validation, 'VALIDATION_WAITING');
            touch($release);
            self::assertSame(0, $arbitration->wait(), $arbitration->getErrorOutput());
            self::assertSame(0, $validation->wait(), $validation->getErrorOutput());
            self::assertStringContainsString('COMPETITOR_PROTECTED', $validation->getOutput());
            self::assertSame(Proposal::ARBITRATION, $alternative->fresh()->status);
            self::assertSame('pending', $preferred->fresh()->status);
            self::assertSame([], $term->fresh()->validated);
        } finally {
            if ($arbitration->isRunning()) {
                $arbitration->stop();
            }
            if ($validation?->isRunning()) {
                $validation->stop();
            }
            if (is_file($release)) {
                unlink($release);
            }
        }
    }
}
