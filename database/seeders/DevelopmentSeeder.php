<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Development seed data is restricted to local/testing environments.');
        }
        $password = env('NETPRIOR_DEMO_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Set NETPRIOR_DEMO_PASSWORD to a password with at least 12 characters before running this opt-in seeder.');
        }
        DB::transaction(function () use ($password) {
            $this->call([RoleSeeder::class, ReferenceDataSeeder::class]);
            foreach (['Library', 'Data Center', 'ICT Office', 'Laboratory'] as $name) {
                Location::firstOrCreate(['name' => $name], ['is_active' => true]);
            }
            if (! User::where('email', 'demo-admin@example.test')->exists()) {
                $user = new User(['name' => 'Development Administrator', 'email' => 'demo-admin@example.test', 'password' => $password]);
                $user->role()->associate(Role::where('code', Role::ADMINISTRATOR)->firstOrFail());
                $user->is_active = true;
                $user->save();
            }
        });
    }
}
