<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireTwoFactor;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Revision;
use App\Models\Term;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CampaignService;
use App\Services\FilteredValidationService;
use App\Services\OperationLock;
use App\Services\PrivateStoragePermissions;
use App\Services\ProposalScreenshots;
use App\Services\SourceRecord;
use App\Services\StorageBudget;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProposalScreenshotTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Term $term;

    private string $privateStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireTwoFactor::class);
        $this->privateStorage = sys_get_temp_dir().'/myabi-screenshots-'.bin2hex(random_bytes(8));
        mkdir($this->privateStorage, 0700);
        $this->app->useStoragePath($this->privateStorage);
        $this->author = User::factory()->create(['roles' => ['translator' => ['fr']], 'organization_id' => null, 'locale' => 'fr']);
        $source = $this->mock(SourceRecord::class);
        $source->shouldReceive('attributes')->andReturn(['label' => ['reference' => 'Synthetic reference', 'translations' => [], 'columns' => ['fr' => 'fr_CH', 'it' => 'it_CH']]]);
        $source->shouldReceive('row')->andReturn(['Name' => 'Synthetic key', 'de_CH' => 'Synthetic reference']);
        $source->shouldReceive('snapshot')->andReturn(new Revision);
        $this->term = Term::create(['type' => 'server', 'identity_hash' => hash('sha256', 'synthetic-screenshot'), 'key' => ['Synthetic key'],
            'label' => 'Synthetic key', 'context' => '', 'source_text' => 'Synthetic reference', 'search_text' => 'Synthetic reference',
            'semantic_hash' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64), 'source_import_id' => 1,
            'source_offset' => 0, 'source_length' => 1, 'validated' => [], 'review_needed' => [], 'created_by' => $this->author->id, 'updated_by' => $this->author->id])->fresh();
        $this->actingAs($this->author);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        File::deleteDirectory($this->privateStorage);
    }

    private function upload(string $type = 'png', int $width = 640, int $height = 360): UploadedFile
    {
        return UploadedFile::fake()->image('synthetic.'.$type, $width, $height);
    }

    private function propose(?UploadedFile $file = null, ?int $supersedes = null): Proposal
    {
        return app(WorkflowService::class)->propose($this->author, $this->term, 'label', 'fr', 'Synthetic proposal', $this->term->lock_version,
            $supersedes, screenshot: $file, screenshotAction: $file ? 'replace' : 'keep');
    }

    public function test_optional_image_is_private_and_served_with_authorization_and_version(): void
    {
        self::assertNull($this->propose()->screenshot_path);
        $file = $this->upload();
        $proposal = $this->propose($file);
        $service = app(ProposalScreenshots::class);
        self::assertSame(hash_file('sha256', $file->getRealPath()), $proposal->screenshot_sha256);
        self::assertSame('image/png', $proposal->screenshot_mime);
        self::assertSame(640, $proposal->screenshot_width);
        self::assertSame([320, 180], array_slice(getimagesize($service->absolute($proposal->screenshot_path, true)), 0, 2));
        self::assertArrayNotHasKey('screenshot_path', $proposal->toArray());
        $url = route('proposals.screenshot', ['proposal' => $proposal->id, 'version' => 1]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $organization = Organisation::create(['code' => 'QB', 'name' => 'Synthetic other organization', 'active' => true]);
        $reader = User::factory()->create(['roles' => ['reader'], 'organization_id' => $organization->id]);
        $this->actingAs($reader)->get($url)->assertOk();
        $this->get(str_replace('version=1', 'version=2', $url))->assertStatus(409);
        $this->get(route('proposals.screenshot', ['proposal' => $proposal->id, 'version' => ['1']]))->assertStatus(409);
        $this->actingAs(User::factory()->create(['active' => false]))->get($url)->assertRedirect('/login');
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect('/login');
    }

    public function test_capture_consultation_keeps_mfa_and_session_revocation_protection(): void
    {
        $proposal = $this->propose($this->upload());
        $url = route('proposals.screenshot', ['proposal' => $proposal->id, 'version' => 1]);
        $this->withMiddleware(RequireTwoFactor::class);
        config(['fortify.mfa_enabled' => true]);
        $validator = User::factory()->create(['roles' => ['validator' => ['fr']], 'organization_id' => null]);
        $this->actingAs($validator)->getJson($url)->assertForbidden();
        config(['fortify.mfa_enabled' => false]);
        $this->getJson($url)->assertOk();
        $this->withSession(['security.last_seen_at' => now()->subHours(2)->timestamp])->getJson($url)->assertUnauthorized();
    }

    public function test_private_write_failure_does_not_change_the_proposal_or_leave_partial_files(): void
    {
        $proposal = $this->propose($this->upload());
        $old = $proposal->screenshot_path;
        $this->partialMock(PrivateStoragePermissions::class)->shouldReceive('secureFile')->andThrow(new \RuntimeException('Synthetic permission failure'));
        try {
            app(WorkflowService::class)->edit($this->author, $proposal, 'Unsaved value', 1, $this->upload(), 'replace');
            self::fail('Write failure ignored');
        } catch (ValidationException) {
            self::assertSame($old, $proposal->fresh()->screenshot_path);
            self::assertSame('Synthetic proposal', $proposal->fresh()->value);
        }
        self::assertCount(1, glob($this->privateStorage.'/app/private/proposal-screenshots/*', GLOB_ONLYDIR));
    }

    public function test_real_content_not_filename_or_client_mime_determines_type(): void
    {
        $file = $this->upload('jpg');
        $renamed = new UploadedFile($file->getRealPath(), 'untrusted.php', 'text/plain', null, true);
        self::assertSame('image/jpeg', $this->propose($renamed)->screenshot_mime);
        $this->expectException(ValidationException::class);
        $this->propose(UploadedFile::fake()->createWithContent('fake.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
    }

    public function test_size_dimensions_and_decoding_are_enforced_before_storage(): void
    {
        $complete = $this->upload();
        $cases = [
            UploadedFile::fake()->createWithContent('large.png', str_repeat('x', ProposalScreenshots::MAX_BYTES + 1)),
            $this->upload('png', 8193, 1),
            $this->upload('png', 4000, 3001),
            UploadedFile::fake()->createWithContent('broken.png', substr(file_get_contents($complete->getRealPath()), 0, 45)),
        ];
        foreach ($cases as $file) {
            try {
                $this->propose($file);
                self::fail('Invalid image accepted');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('screenshot', $error->errors());
            }
        }
        self::assertSame(0, Proposal::count());
        self::assertDirectoryDoesNotExist($this->privateStorage.'/app/private/proposal-screenshots');
    }

    public function test_exact_byte_limit_and_alpha_are_preserved(): void
    {
        $file = $this->upload('png', 1, 1);
        $path = $file->getRealPath();
        file_put_contents($path, str_repeat("\0", ProposalScreenshots::MAX_BYTES - filesize($path)), FILE_APPEND);
        $proposal = $this->propose(new UploadedFile($path, 'limit.png', 'image/png', null, true));
        self::assertSame(ProposalScreenshots::MAX_BYTES, $proposal->screenshot_bytes);
        $alpha = imagecreatetruecolor(20, 20);
        imagealphablending($alpha, false);
        imagesavealpha($alpha, true);
        imagefill($alpha, 0, 0, imagecolorallocatealpha($alpha, 255, 0, 0, 100));
        ob_start();
        imagepng($alpha);
        $png = ob_get_clean();
        $transparent = $this->propose(UploadedFile::fake()->createWithContent('alpha.png', $png));
        $thumb = imagecreatefrompng(app(ProposalScreenshots::class)->absolute($transparent->screenshot_path, true));
        self::assertSame(100, imagecolorsforindex($thumb, imagecolorat($thumb, 0, 0))['alpha']);
    }

    public function test_jpeg_exif_orientation_is_applied_to_the_thumbnail_only(): void
    {
        $jpeg = $this->upload('jpg', 80, 40);
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 0x0112, 3, 1, 6)."\0\0".pack('V', 0);
        $contents = file_get_contents($jpeg->getRealPath());
        $contents = substr($contents, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($contents, 2);
        $proposal = $this->propose(UploadedFile::fake()->createWithContent('oriented.jpg', $contents));
        self::assertSame(hash('sha256', $contents), $proposal->screenshot_sha256);
        self::assertSame([40, 80], array_slice(getimagesize(app(ProposalScreenshots::class)->absolute($proposal->screenshot_path, true)), 0, 2));
    }

    public function test_campaign_image_uses_one_outer_lock_and_rolls_back_with_the_campaign(): void
    {
        $manager = User::factory()->create(['roles' => ['manager'], 'organization_id' => null]);
        $service = app(CampaignService::class);
        $campaign = $service->create($manager, ['title' => 'Synthetic screenshot campaign', 'language' => 'fr', 'objective' => 'translation', 'assignee_ids' => [$this->author->id]]);
        $service->addDirectTerms($manager, $campaign, [$this->term->id]);
        $service->start($manager, $campaign);
        DB::beginTransaction();
        $proposal = $service->propose($this->author, $campaign, $this->term, 'Campaign draft', 1, $this->upload(), 'replace');
        $image = app(ProposalScreenshots::class)->absolute($proposal->screenshot_path);
        self::assertFileExists($image);
        DB::rollBack();
        self::assertFileDoesNotExist($image);
        self::assertSame(0, Proposal::count());
        $proposal = $service->propose($this->author, $campaign, $this->term, 'Campaign draft', 1, $this->upload(), 'replace');
        self::assertFileExists(app(ProposalScreenshots::class)->absolute($proposal->screenshot_path));
        self::assertSame($proposal->id, $campaign->participations()->first()->tracked_proposal_id);
    }

    public function test_image_change_invalidates_filtered_validation_confirmation(): void
    {
        $proposal = $this->propose($this->upload());
        $validator = User::factory()->create(['roles' => ['validator' => ['fr']], 'organization_id' => null]);
        $service = app(FilteredValidationService::class);
        $token = $service->snapshot($validator, ['language' => 'fr'], 1, (int) DB::table('catalogue_state')->value('generation'));
        app(WorkflowService::class)->edit($this->author, $proposal, $proposal->value, 1, screenshotAction: 'remove');
        $this->expectException(ValidationException::class);
        $service->enqueue($validator, $token, false);
    }

    public function test_replace_remove_and_stale_decisions_preserve_the_correct_files(): void
    {
        $workflow = app(WorkflowService::class);
        $service = app(ProposalScreenshots::class);
        $proposal = $this->propose($this->upload());
        $old = $service->absolute($proposal->screenshot_path);
        $generation = DB::table('catalogue_state')->value('generation');
        $proposal = $workflow->edit($this->author, $proposal, $proposal->value, 1, $this->upload('jpg'), 'replace');
        self::assertSame(2, $proposal->lock_version);
        self::assertFileDoesNotExist($old);
        self::assertGreaterThan($generation, DB::table('catalogue_state')->value('generation'));
        $validator = User::factory()->create(['roles' => ['validator' => ['fr']], 'organization_id' => null]);
        try {
            $workflow->decide($validator, $proposal, 'reject', 1, 'Synthetic rejection');
            self::fail('Stale decision accepted');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('version', $error->errors());
        }
        $image = $service->absolute($proposal->screenshot_path);
        $proposal = $workflow->edit($this->author, $proposal, $proposal->value, 2, screenshotAction: 'remove');
        self::assertNull($proposal->screenshot_path);
        self::assertFileDoesNotExist($image);
        self::assertSame(3, $proposal->lock_version);
    }

    public function test_decisions_freeze_images_and_rejection_copies_are_independent(): void
    {
        $workflow = app(WorkflowService::class);
        $service = app(ProposalScreenshots::class);
        $rejected = $this->propose($this->upload());
        $validator = User::factory()->create(['roles' => ['validator' => ['fr']], 'organization_id' => null]);
        $workflow->decide($validator, $rejected, 'reject', 1, 'Synthetic reason');
        $rejected->refresh();
        $copy = $this->propose(supersedes: $rejected->id);
        self::assertNotSame($rejected->screenshot_path, $copy->screenshot_path);
        self::assertSame($rejected->screenshot_sha256, $copy->screenshot_sha256);
        $workflow->edit($this->author, $copy, $copy->value, 1, screenshotAction: 'remove');
        self::assertFileExists($service->absolute($rejected->screenshot_path));
        try {
            $workflow->edit($this->author, $rejected, $rejected->value, 2, screenshotAction: 'remove');
            self::fail('Rejected image changed');
        } catch (ValidationException) {
            self::assertSame($rejected->screenshot_path, $rejected->fresh()->screenshot_path);
        }
        $validated = $this->propose($this->upload());
        $workflow->decide($validator, $validated, 'validate', 1);
        self::assertFileExists($service->absolute($validated->fresh()->screenshot_path));
    }

    public function test_outer_rollback_and_audit_failure_clean_new_files_and_keep_previous_image(): void
    {
        $proposal = $this->propose($this->upload());
        $oldPath = $proposal->screenshot_path;
        DB::beginTransaction();
        $changed = app(WorkflowService::class)->edit($this->author, $proposal, 'Temporary value', 1, $this->upload('jpg'), 'replace');
        $newPath = $changed->screenshot_path;
        DB::rollBack();
        self::assertSame($oldPath, $proposal->fresh()->screenshot_path);
        self::assertFileExists(app(ProposalScreenshots::class)->absolute($oldPath));
        self::assertFileDoesNotExist(app(ProposalScreenshots::class)->absolute($newPath));
        $this->mock(AuditService::class)->shouldReceive('record')->andThrow(new \RuntimeException('Synthetic audit failure'));
        try {
            $this->propose($this->upload());
            self::fail('Audit failure ignored');
        } catch (\RuntimeException) {
            self::assertSame(1, Proposal::count());
        }
        self::assertCount(1, glob($this->privateStorage.'/app/private/proposal-screenshots/*', GLOB_ONLYDIR));
    }

    public function test_quota_and_exclusive_operation_lock_preserve_existing_image(): void
    {
        $proposal = $this->propose($this->upload());
        $old = $proposal->screenshot_path;
        $this->mock(StorageBudget::class)->shouldReceive('ensure')->andThrow(ValidationException::withMessages(['storage' => 'Synthetic quota']));
        try {
            app(WorkflowService::class)->edit($this->author, $proposal, 'Unsaved text', 1, $this->upload(), 'replace');
            self::fail('Quota ignored');
        } catch (ValidationException) {
            self::assertSame($old, $proposal->fresh()->screenshot_path);
        }
        app(OperationLock::class)->run(function () {
            $this->expectException(ValidationException::class);
            $this->propose($this->upload());
        });
    }

    public function test_http_contract_errors_retain_text_and_do_not_expose_private_paths(): void
    {
        $payload = ['term_id' => $this->term->id, 'attribute' => 'label', 'language' => 'fr', 'value' => 'Retained synthetic draft',
            'lock_version' => 1, 'screenshot_action' => 'replace', 'screenshot' => $this->upload()];
        $response = $this->postJson('/terms/'.$this->term->id.'/proposals', $payload)->assertCreated()->assertJsonStructure(['id', 'version', 'message']);
        $response->assertDontSee('proposal-screenshots');
        $proposal = Proposal::findOrFail($response->json('id'));
        $this->post('/proposals/'.$proposal->id, ['_method' => 'PATCH', 'value' => 'Retained synthetic draft', 'lock_version' => 0, 'screenshot_action' => 'remove'])
            ->assertSessionHasErrors('version')->assertSessionHasInput('value', 'Retained synthetic draft');
        $this->postJson('/proposals/'.$proposal->id, ['_method' => 'PATCH', 'value' => $proposal->value, 'lock_version' => 1, 'screenshot_action' => 'keep', 'screenshot' => $this->upload()])
            ->assertUnprocessable()->assertJsonValidationErrors('screenshot');
        $other = User::factory()->create(['roles' => ['translator' => ['fr']], 'organization_id' => null]);
        $this->actingAs($other)->postJson('/proposals/'.$proposal->id, ['_method' => 'PATCH', 'value' => 'Other', 'lock_version' => 1, 'screenshot_action' => 'remove'])->assertForbidden();
    }

    public function test_prune_is_dry_by_default_and_never_removes_referenced_or_recent_files(): void
    {
        $proposal = $this->propose($this->upload());
        $root = $this->privateStorage.'/app/private/proposal-screenshots/';
        $orphan = $root.str_repeat('c', 32);
        mkdir($orphan);
        file_put_contents($orphan.'/image.png', 'Synthetic orphan');
        touch($orphan.'/image.png', time() - 90000);
        touch($orphan, time() - 90000);
        $recent = $root.str_repeat('d', 32);
        mkdir($recent);
        $this->artisan('proposals:screenshots:prune')->assertSuccessful();
        self::assertDirectoryExists($orphan);
        $this->artisan('proposals:screenshots:prune', ['--apply' => true])->assertSuccessful();
        self::assertDirectoryDoesNotExist($orphan);
        self::assertDirectoryExists($recent);
        self::assertFileExists(app(ProposalScreenshots::class)->absolute($proposal->screenshot_path));
    }
}
