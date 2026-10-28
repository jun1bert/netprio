<?php

namespace Tests\Feature;

use App\Models\Criterion;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_seeding_is_idempotent_and_does_not_create_decision_data(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $criterion = Criterion::where('code', 'PERFORMANCE')->firstOrFail();
        $criterion->update(['name' => 'Institution-specific performance rubric']);
        $this->seed(ReferenceDataSeeder::class);
        $this->assertDatabaseCount('criteria', 7);
        $this->assertDatabaseCount('asset_types', 9);
        $this->assertSame('Institution-specific performance rubric', $criterion->fresh()->name);
        $this->assertDatabaseCount('network_assets', 0);
        $this->assertDatabaseCount('assessments', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(7, Criterion::whereNull('weight')->count());
    }

    public function test_development_seeder_refuses_a_production_environment(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(\RuntimeException::class);
        (new DevelopmentSeeder)->run();
    }
}
