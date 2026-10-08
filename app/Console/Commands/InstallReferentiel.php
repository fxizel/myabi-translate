<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class InstallReferentiel extends Command
{
    protected $signature = 'referentiel:install {--email= : First administrator email} {--name= : Administrator name}';

    protected $description = 'Initialize root organization, non-connectable import account and first administrator';

    public function handle(): int
    {
        $root = Organisation::firstOrCreate(['code' => 'ARGE'], ['name' => 'ARGE-ABI', 'is_root' => true, 'active' => true]);
        User::firstOrCreate(['email' => 'import@referentiel.invalid'], ['name' => 'Import DEVCONF', 'password' => Hash::make(bin2hex(random_bytes(32))), 'is_technical' => true, 'active' => false, 'organization_id' => $root->id, 'roles' => [], 'languages' => []]);
        if (User::where('is_technical', false)->exists()) {
            $this->info('Application already initialized.');

            return self::SUCCESS;
        }
        $email = $this->option('email') ?: $this->ask('Administrator professional email');
        $name = $this->option('name') ?: $this->ask('Administrator full name');
        $minimumLength = config('auth.password_min_length');
        $password = $this->secret("Password (at least {$minimumLength} characters; never logged)");
        validator(compact('email', 'name', 'password'), ['email' => 'required|email', 'name' => 'required|string', 'password' => 'required|string|min:'.$minimumLength])->validate();
        $user = User::create(['email' => $email, 'name' => $name, 'password' => $password, 'organization_id' => $root->id, 'roles' => ['admin' => ['de', 'fr', 'it', 'en'], 'manager' => ['de', 'fr', 'it', 'en']], 'languages' => ['de', 'fr', 'it', 'en'], 'active' => true]);
        app(AuditService::class)->record('application.initialized', $user, [], [], $user);
        $this->info(config('fortify.mfa_enabled', false)
            ? 'Administrator created. TOTP enrollment is required at first sign-in.'
            : 'Administrator created. MFA is disabled by the server configuration.');

        return self::SUCCESS;
    }
}
