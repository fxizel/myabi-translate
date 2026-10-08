<?php

namespace Database\Seeders;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $root = Organisation::firstOrCreate(['code' => 'ARGE'], ['name' => 'ARGE-ABI', 'is_root' => true, 'active' => true]);
        User::firstOrCreate(['email' => 'import@referentiel.invalid'], ['name' => 'Import DEVCONF', 'password' => Hash::make(bin2hex(random_bytes(32))),
            'is_technical' => true, 'active' => false, 'organization_id' => $root->id, 'roles' => [], 'languages' => []]);
    }
}
