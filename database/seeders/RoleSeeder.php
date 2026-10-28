<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            Role::ADMINISTRATOR => 'Administrator',
            Role::EVALUATOR => 'ICT / Network Evaluator',
            Role::MANAGEMENT => 'Decision Maker / Management',
        ] as $code => $name) {
            Role::firstOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
