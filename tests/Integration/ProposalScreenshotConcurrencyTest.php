<?php

namespace Tests\Integration;

use App\Models\Proposal;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\ProposalScreenshots;
use App\Services\SourceRecord;
use App\Services\StorageBudget;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Support\BrowserExtensionConcurrencyFixture as Fixture;
use Tests\Support\DatabaseProcess;
use Tests\Support\ProposalScreenshotSource;
use Tests\TestCase;

class ProposalScreenshotConcurrencyTest extends TestCase
{
    private string $directory;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('MYABI_ISOLATED_TESTS') !== '1' || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires an explicitly isolated portable MariaDB instance.');
        }
        Fixture::assertIsolated();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->directory = getenv('MYABI_CONCURRENCY_DIRECTORY').'/screenshots-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->app->useStoragePath($this->directory);
        app()->instance(SourceRecord::class, new ProposalScreenshotSource);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        parent::tearDown();
    }

    private function fixture(): array
    {
        $author = User::factory()->create(['roles' => ['translator' => ['fr']], 'organization_id' => null]);
        $validator = User::factory()->create(['roles' => ['validator' => ['fr']], 'organization_id' => null]);
        $term = Fixture::term('Synthetic screenshot', $author->id);
        $proposal = app(WorkflowService::class)->propose($author, $term, 'label', 'fr', 'Synthetic value', 1, screenshot: UploadedFile::fake()->image('synthetic.png'), screenshotAction: 'replace');
        $image = $this->directory.'/replacement.png';
        $file = UploadedFile::fake()->image('replacement.png', 60, 40);
        copy($file->getRealPath(), $image);

        return [$author, $validator, $proposal, $image];
    }

    private function writer(User $author, Proposal $proposal, string $image, bool $pause, ?int $quota = null): Process
    {
        $body = <<<'PHP'
        \Tests\Support\BrowserExtensionConcurrencyFixture::assertIsolated();
        app()->instance(\App\Services\SourceRecord::class, new \Tests\Support\ProposalScreenshotSource);
        if ($parameters['quota'] !== null) config(['referentiel.storage_quota' => $parameters['quota']]);
        if ($parameters['pause']) {
            \Illuminate\Support\Facades\DB::listen(function ($query) use ($parameters) {
                if (str_starts_with(strtolower($query->sql), 'update `proposals`') && str_contains($query->sql, 'screenshot_path')) {
                    echo "IMAGE_WRITTEN\n"; flush();
                    \Tests\Support\BrowserExtensionConcurrencyFixture::wait($parameters['release']);
                }
            });
        }
        try {
            app(\App\Services\WorkflowService::class)->edit(\App\Models\User::findOrFail($parameters['author']), \App\Models\Proposal::findOrFail($parameters['proposal']),
                'Synthetic value', 1, new \Illuminate\Http\UploadedFile($parameters['image'], 'synthetic.png', 'image/png', null, true), 'replace');
            echo "SAVED\n";
        } catch (\Illuminate\Validation\ValidationException $error) {
            echo isset($error->errors()['operation']) ? "PAUSED\n" : "REJECTED\n";
        }
        PHP;
        $worker = DatabaseProcess::start($body, ['author' => $author->id, 'proposal' => $proposal->id, 'image' => $image, 'pause' => $pause, 'release' => $this->directory.'/release', 'quota' => $quota]);
        $this->workers[] = $worker;

        return $worker;
    }

    public function test_an_image_write_blocks_a_parallel_decision_then_rejects_its_old_version(): void
    {
        [$author, $validator, $proposal, $image] = $this->fixture();
        $old = app(ProposalScreenshots::class)->absolute($proposal->screenshot_path);
        $writer = $this->writer($author, $proposal, $image, true);
        DatabaseProcess::awaitOutput($writer, 'IMAGE_WRITTEN');
        try {
            app(WorkflowService::class)->decide($validator, $proposal, 'reject', 1, 'Synthetic rejection');
            self::fail('Parallel decision accepted');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('operation', $error->errors());
        } finally {
            touch($this->directory.'/release');
        }
        self::assertSame(0, $writer->wait(), $writer->getErrorOutput());
        self::assertStringContainsString('SAVED', $writer->getOutput());
        self::assertFileDoesNotExist($old);
        self::assertSame(2, $proposal->fresh()->lock_version);
        $this->expectException(ValidationException::class);
        app(WorkflowService::class)->decide($validator, $proposal, 'reject', 1, 'Synthetic rejection');
    }

    public function test_two_processes_cannot_write_images_at_the_same_time(): void
    {
        [$author, $validator, $proposal, $image] = $this->fixture();
        $quota = app(StorageBudget::class)->usage()['bytes'] + filesize($image) + 1_048_576 + 1024;
        $first = $this->writer($author, $proposal, $image, true, $quota);
        DatabaseProcess::awaitOutput($first, 'IMAGE_WRITTEN');
        $second = $this->writer($author, $proposal, $image, false, $quota);
        try {
            self::assertSame(0, $second->wait(), $second->getErrorOutput());
            self::assertStringContainsString('PAUSED', $second->getOutput());
        } finally {
            touch($this->directory.'/release');
        }
        self::assertSame(0, $first->wait(), $first->getErrorOutput());
        self::assertCount(1, glob($this->directory.'/app/private/proposal-screenshots/*', GLOB_ONLYDIR));
        self::assertLessThanOrEqual($quota, app(StorageBudget::class)->usage()['bytes']);
    }

    public function test_campaign_transaction_retry_leaves_one_proposal_and_one_image(): void
    {
        [$author] = $this->fixture();
        $manager = User::factory()->create(['roles' => ['manager'], 'organization_id' => null]);
        $term = Fixture::term('Synthetic retry', $author->id);
        $campaigns = app(CampaignService::class);
        $campaign = $campaigns->create($manager, ['title' => 'Synthetic retry', 'language' => 'fr', 'objective' => 'translation', 'assignee_ids' => [$author->id]]);
        $campaigns->addDirectTerms($manager, $campaign, [$term->id]);
        $campaigns->start($manager, $campaign);
        $attempts = 0;
        DB::listen(function ($query) use (&$attempts) {
            if (str_starts_with(strtolower($query->sql), 'insert into `audit_events`') && in_array('proposal.created', $query->bindings, true) && ++$attempts === 1) {
                throw new \PDOException('Deadlock found when trying to get lock');
            }
        });
        $proposal = $campaigns->propose($author, $campaign, $term, 'Synthetic retry', 1, UploadedFile::fake()->image('retry.png'), 'replace');
        self::assertSame(2, $attempts);
        self::assertSame(1, Proposal::where('term_id', $term->id)->count());
        self::assertFileExists(app(ProposalScreenshots::class)->absolute($proposal->screenshot_path));
        self::assertCount(2, glob($this->directory.'/app/private/proposal-screenshots/*', GLOB_ONLYDIR));
    }
}
