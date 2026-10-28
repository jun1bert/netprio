<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Models\PrioritizationRun;
use App\Services\AhpService;
use App\Services\AssessmentService;
use App\Services\PrioritizationService;
use App\Services\RunComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsNetpriorRecords;
use Tests\TestCase;

class WorkflowUsabilityTest extends TestCase
{
    use BuildsNetpriorRecords,RefreshDatabase;

    public function test_queue_separates_unstarted_drafts_and_overdue_and_honors_edit_permissions(): void
    {
        $actor = $this->institutionUser();
        $this->actingAs($actor);
        foreach (['NEW', 'DRAFT', 'OLD', 'CURRENT', 'RETIRED'] as $code) {
            $asset = NetworkAsset::create(array_replace($this->assetValues(), ['asset_code' => $code, 'status' => $code === 'RETIRED' ? 'retired' : 'active']));
            if (in_array($code, ['DRAFT', 'OLD', 'CURRENT'])) {
                Assessment::create(['network_asset_id' => $asset->id, 'assessed_by' => $actor->id, 'assessment_date' => $code === 'OLD' ? today()->subYears(3) : today(), 'status' => $code === 'DRAFT' ? 'draft' : 'completed']);
            }
        }
        $this->get(route('assets.index', ['assessment_status' => 'not_started']))->assertOk()->assertSee('NEW')->assertDontSee('RETIRED')->assertDontSee('DRAFT');
        $this->get(route('assets.index', ['assessment_status' => 'draft']))->assertOk()->assertSee('DRAFT')->assertSee('Continue draft')->assertDontSee('CURRENT');
        $this->get(route('assets.index', ['assessment_status' => 'overdue']))->assertOk()->assertSee('OLD')->assertSee('Due for review')->assertDontSee('CURRENT');
        $this->actingAs($this->institutionUser('management'))->get(route('assets.index', ['assessment_status' => 'draft']))->assertOk()->assertDontSee('Continue draft')->assertDontSee('Start assessment');
        $this->actingAs($actor)->get(route('assessments.create'))->assertOk()->assertSee('data-searchable-select', false)->assertSee('Data center')->assertDontSee('RETIRED');
    }

    public function test_comparison_preserves_snapshots_and_explains_score_rank_and_membership_changes(): void
    {
        $actor = $this->institutionUser();
        $this->actingAs($actor);
        $criterion = Criterion::create(['code' => 'PERF', 'name' => 'Performance', 'direction' => 'benefit']);
        app(AhpService::class)->save($actor, []);
        $first = NetworkAsset::create($this->assetValues());
        $second = NetworkAsset::create(array_replace($this->assetValues(), ['asset_code' => 'SW-002']));
        foreach ([[$first, 2], [$second, 4]] as [$asset,$score]) {
            app(AssessmentService::class)->save($actor, ['network_asset_id' => $asset->id, 'assessment_date' => today()->toDateString(), 'status' => 'completed', 'scores' => [$criterion->id => ['score' => $score]]]);
        }
        $before = app(PrioritizationService::class)->run($actor, 'Baseline');
        $snapshot = $before->decision_snapshot;
        app(AssessmentService::class)->save($actor, ['network_asset_id' => $first->id, 'assessment_date' => today()->toDateString(), 'status' => 'completed', 'scores' => [$criterion->id => ['score' => 5]]]);
        $after = app(PrioritizationService::class)->run($actor, 'Review');
        $comparison = app(RunComparisonService::class)->compare($before, $after);
        $row = $comparison['rows']->firstWhere('asset.asset_code', 'SW-001');
        $this->assertSame(1, $row['rank_change']);
        $this->assertEquals(75, $row['score_change']);
        $this->assertSame([], $comparison['warnings']);
        $this->get(route('runs.compare', [$after, 'against' => $before->id]))->assertOk()->assertSee('Up 1')->assertSee('+75.00');
        $scoped = app(PrioritizationService::class)->run($actor, 'Selected only', [$first->id]);
        $comp = app(RunComparisonService::class)->compare($before, $scoped);
        $this->assertStringContainsString('membership', implode(' ', $comp['warnings']));
        $this->assertNull($comp['rows']->firstWhere('asset.asset_code', 'SW-002')['rank_change']);
        $changed = $scoped->decision_snapshot;
        $changed['criteria'][0]['weight'] = 0.8;
        $changed['thresholds']['critical'] = 95;
        $changed['algorithm'] = 'other';
        $scoped->update(['decision_snapshot' => $changed]);
        $this->assertCount(4, app(RunComparisonService::class)->compare($before, $scoped)['warnings']);
        $this->assertSame($snapshot, $before->fresh()->decision_snapshot);
        $this->actingAs($this->institutionUser('management'))->get(route('runs.compare', [$after, 'against' => $before->id]))->assertOk();
        $this->actingAs($this->institutionUser('unassigned'))->get(route('runs.compare', $after))->assertForbidden();
    }

    public function test_comparison_handles_missing_baseline_invalid_choices_and_draft_history(): void
    {
        $actor = $this->institutionUser();
        $this->actingAs($actor);
        $run = PrioritizationRun::create(['name' => 'Only run', 'created_by' => $actor->id, 'status' => 'completed', 'completed_at' => now(), 'decision_snapshot' => []]);
        $this->get(route('runs.compare', $run))->assertOk()->assertSee('A second completed run is needed');
        $this->get(route('runs.compare', [$run, 'against' => $run->id]))->assertSessionHasErrors('against');
        $draft = PrioritizationRun::create(['name' => 'Hidden draft', 'created_by' => $actor->id, 'status' => 'draft']);
        $this->get(route('runs.compare', [$run, 'against' => $draft->id]))->assertNotFound();
        $this->get(route('runs.compare',$draft))->assertNotFound();
        $this->assertSame(2,PrioritizationRun::count());
    }
}
