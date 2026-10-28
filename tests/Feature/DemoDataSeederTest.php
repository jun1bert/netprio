<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Criterion;
use App\Models\Location;
use App\Models\NetworkAsset;
use App\Models\PairwiseComparison;
use App\Models\PrioritizationRun;
use App\Services\AhpService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsNetpriorRecords;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use BuildsNetpriorRecords, RefreshDatabase;

    public function test_demo_dataset_preserves_existing_records_judgments_and_history_on_rerun(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $a = Criterion::create(['code' => 'PERFORMANCE', 'name' => 'Institution rubric', 'direction' => 'benefit']);
        $b = Criterion::create(['code' => 'COST', 'name' => 'Institution cost rubric', 'direction' => 'cost']);
        app(AhpService::class)->save($admin, [$a->id.'_'.$b->id => 3]);
        $weights = Criterion::pluck('weight', 'id')->all();
        $existing = NetworkAsset::create($this->assetValues());
        $assessment = Assessment::create(['network_asset_id' => $existing->id, 'assessed_by' => $admin->id, 'assessment_date' => today(), 'status' => 'completed']);
        $assessment->assessmentScores()->create(['criterion_id' => $a->id, 'score' => 5]);
        $assessment->assessmentScores()->create(['criterion_id' => $b->id, 'score' => 1]);
        $this->seed(DemoDataSeeder::class);
        $this->assertDatabaseCount('network_assets', 13);
        $this->assertSame(6, Location::where('name', 'like', 'DEMO - %')->count());
        $this->assertDatabaseCount('assessments', 13);
        $run = PrioritizationRun::firstOrFail();
        $this->assertSame(8, $run->prioritizationResults()->count());
        $this->assertCount(4, $run->decision_snapshot['skipped_assets']);
        $this->assertFalse($run->prioritizationResults()->where('network_asset_id', $existing->id)->exists());
        $this->assertSame(3.0, PairwiseComparison::first()->comparison_value);
        $this->assertSame($weights, Criterion::pluck('weight', 'id')->all());
        $demo = NetworkAsset::where('asset_code', 'DEMO-SW-CORE-001')->firstOrFail();
        $this->assertSame(2, $demo->assessments()->count());
        $demo->update(['name' => 'User edited demo name']);
        $this->seed(DemoDataSeeder::class);
        $this->assertDatabaseCount('network_assets', 13);
        $this->assertDatabaseCount('assessments', 13);
        $this->assertDatabaseCount('prioritization_runs', 1);
        $this->assertSame('User edited demo name', $demo->fresh()->name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'demo.dataset_seeded']);
    }

    public function test_demo_seeding_is_not_available_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(\RuntimeException::class);
        (new DemoDataSeeder)->run();
    }
}
