<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Models\PrioritizationRun;
use App\Services\AhpService;
use App\Services\PrioritizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsNetpriorRecords;
use Tests\TestCase;

class PrioritizationWorkflowTest extends TestCase
{
    use BuildsNetpriorRecords, RefreshDatabase;

    public function test_known_directional_scores_rankings_ties_snapshots_and_exclusions(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $benefit = Criterion::create(['code' => 'SEC', 'name' => 'Security risk', 'direction' => 'benefit']);
        $cost = Criterion::create(['code' => 'COST', 'name' => 'Upgrade cost', 'direction' => 'cost']);
        app(AhpService::class)->save($admin, [$benefit->id.'_'.$cost->id => 1]);
        $base = $this->assetValues();
        foreach ([[5, 1], [3, 3], [3, 3], [1, 5], [5, null]] as $i => [$a,$b]) {
            $asset = NetworkAsset::create(array_replace($base, ['asset_code' => 'SW-'.($i + 1), 'name' => 'Asset '.($i + 1)]));
            $assessment = Assessment::create(['network_asset_id' => $asset->id, 'assessed_by' => $admin->id, 'assessment_date' => today(), 'status' => 'completed']);
            $assessment->assessmentScores()->create(['criterion_id' => $benefit->id, 'score' => $a]);
            if ($b !== null) {
                $assessment->assessmentScores()->create(['criterion_id' => $cost->id, 'score' => $b]);
            }
        }
        NetworkAsset::create(array_replace($base, ['asset_code' => 'EMPTY']));
        NetworkAsset::create(array_replace($base, ['asset_code' => 'RETIRED', 'status' => 'retired']));
        $this->get(route('runs.create'))->assertOk();
        $this->post(route('runs.store'), ['name' => 'Known decision run'])->assertRedirect();
        $run = PrioritizationRun::firstOrFail();
        $results = $run->prioritizationResults()->orderBy('id')->get();
        $this->assertSame([100.0, 50.0, 50.0, 0.0], $results->pluck('final_score')->all());
        $this->assertSame([1, 2, 2, 4], $results->pluck('rank')->all());
        $this->assertSame(['critical', 'medium', 'medium', 'low'], $results->pluck('priority_level')->all());
        $this->assertCount(3, $run->decision_snapshot['skipped_assets']);
        foreach ($results as $result) {
            $this->assertEqualsWithDelta($result->final_score, array_sum(array_column($result->score_breakdown, 'contribution')), 1e-10);
        }
        $this->get(route('runs.index'))->assertOk();
        $this->get(route('runs.show', $run))->assertOk()->assertSee('Known decision run');
        $this->get(route('runs.result', [$run, $results->first()]))->assertOk()->assertSee('Security risk');
        $benefit->update(['name' => 'Changed criterion']);
        NetworkAsset::where('asset_code', 'SW-1')->update(['name' => 'Renamed asset']);
        $this->assertSame('Asset 1', $results->first()->fresh()->asset_snapshot['name']);
        $this->assertSame('Security risk', $results->first()->fresh()->score_breakdown[0]['name']);
        $manager = $this->institutionUser('management');
        $this->actingAs($manager)->get(route('runs.show', $run))->assertOk();
        $this->post(route('runs.store'), ['name' => 'Forbidden'])->assertForbidden();
        $this->assertDatabaseCount('prioritization_runs', 1);
    }

    public function test_no_eligible_assets_or_stale_weights_leave_no_partial_run(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $criterion = Criterion::create(['code' => 'A', 'name' => 'A', 'direction' => 'benefit']);
        $this->post(route('runs.store'), ['name' => 'Stale'])->assertSessionHasErrors('ahp');
        app(AhpService::class)->save($admin, []);
        $this->post(route('runs.store'), ['name' => 'Empty'])->assertSessionHasErrors('assets');
        $this->assertDatabaseCount('prioritization_runs', 0);
    }

    public function test_policy_threshold_boundaries_and_cost_reversal(): void
    {
        $service = app(PrioritizationService::class);
        $t = ['critical' => 80, 'high' => 60, 'medium' => 40];
        foreach ([0 => 'low', 39 => 'low', 40 => 'medium', 59 => 'medium', 60 => 'high', 79 => 'high', 80 => 'critical', 100 => 'critical'] as $score => $level) {
            $this->assertSame($level, $service->classify($score, $t));
        }
        $this->assertSame(1.0, $service->normalize(1, 'cost'));
        $this->assertSame(0.0, $service->normalize(5, 'cost'));
        $this->assertSame(0.5, $service->normalize(3, 'benefit'));
    }
}
