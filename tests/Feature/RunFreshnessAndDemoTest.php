<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Models\PairwiseComparison;
use App\Models\PrioritizationRun;
use App\Models\SystemSetting;
use App\Services\AhpService;
use App\Services\AssessmentService;
use App\Services\DemoDataService;
use App\Services\PrioritizationService;
use App\Services\RunStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsNetpriorRecords;
use Tests\TestCase;

class RunFreshnessAndDemoTest extends TestCase
{
    use BuildsNetpriorRecords,RefreshDatabase;

    private function fixture(bool $demo = false): array
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $criterion = Criterion::create(['code' => 'PERFORMANCE', 'name' => 'Performance', 'direction' => 'benefit']);
        app(AhpService::class)->save($admin, []);
        $asset = NetworkAsset::create($this->assetValues());
        $asset->is_demo = $demo;
        $asset->save();
        $assessment = app(AssessmentService::class)->save($admin, ['network_asset_id' => $asset->id, 'assessment_date' => today()->toDateString(), 'status' => 'completed', 'scores' => [$criterion->id => ['score' => 4, 'remarks' => 'Observed performance shortfall']]]);

        return compact('admin', 'criterion', 'asset', 'assessment');
    }

    public function test_run_fingerprint_is_stable_and_detects_assessment_and_metadata_changes_without_recalculating_history(): void
    {
        extract($this->fixture());
        $run = app(PrioritizationService::class)->run($admin, 'Baseline');
        $this->assertSame('current', app(RunStatusService::class)->inspect($run)['state']);
        $original = $run->decision_snapshot;
        $score = $run->prioritizationResults()->first()->final_score;
        Assessment::create(['network_asset_id' => $asset->id, 'assessed_by' => $admin->id, 'assessment_date' => today(), 'status' => 'draft']);
        $this->assertSame('current', app(RunStatusService::class)->inspect($run)['state']);
        app(AssessmentService::class)->save($admin, ['network_asset_id' => $asset->id, 'assessment_date' => today()->toDateString(), 'status' => 'completed', 'scores' => [$criterion->id => ['score' => 5]]]);
        $status = app(RunStatusService::class)->inspect($run);
        $this->assertSame('changed', $status['state']);
        $this->assertStringContainsString('assessment', implode(' ', $status['changes']));
        $this->assertSame($original, $run->fresh()->decision_snapshot);
        $this->assertSame($score, $run->prioritizationResults()->first()->final_score);
    }

    public function test_criterion_comparison_and_policy_changes_are_explained(): void
    {
        extract($this->fixture());
        $other = Criterion::create(['code' => 'SECURITY', 'name' => 'Security', 'direction' => 'benefit']);
        app(AhpService::class)->save($admin, [$criterion->id.'_'.$other->id => 1]);
        $assessment->assessmentScores()->create(['criterion_id' => $other->id, 'score' => 4]);
        $run = app(PrioritizationService::class)->run($admin, 'Before changes');
        $this->assertSame('current', app(RunStatusService::class)->inspect($run)['state']);
        $criterion->update(['rubric' => array_fill(1, 5, 'Updated institutional scoring guide')]);
        PairwiseComparison::query()->update(['comparison_value' => 3]);
        SystemSetting::create(['key' => 'policy', 'value' => ['institution_name' => 'Updated HEI', 'reassessment_months' => 6, 'thresholds' => ['critical' => 90, 'high' => 70, 'medium' => 50]]]);
        $status = app(RunStatusService::class)->inspect($run);
        $this->assertSame('changed', $status['state']);
        $this->assertStringContainsString('criteria', strtolower(implode(' ', $status['changes'])));
        $this->assertStringContainsString('comparison', implode(' ', $status['changes']));
        $this->assertStringContainsString('policy', implode(' ', $status['changes']));
    }

    public function test_scoped_rerun_uses_current_inputs_but_preserves_original_selection_and_history(): void
    {
        extract($this->fixture(true));
        $outside = NetworkAsset::create(array_replace($this->assetValues(), ['asset_code' => 'OUTSIDE']));
        app(AssessmentService::class)->save($admin, ['network_asset_id' => $outside->id, 'assessment_date' => today()->toDateString(), 'status' => 'completed', 'scores' => [$criterion->id => ['score' => 5]]]);
        $run = app(PrioritizationService::class)->run($admin, 'Selected demo baseline', [$asset->id]);
        $saved = $run->decision_snapshot;
        $outside->update(['name' => 'Changed outside scope']);
        $this->assertSame('current', app(RunStatusService::class)->inspect($run)['state']);
        $asset->update(['name' => 'Updated selected asset']);
        $this->assertSame('changed', app(RunStatusService::class)->inspect($run)['state']);
        $this->get(route('runs.create', ['rerun_of' => $run->id]))->assertOk()->assertSee('original selected asset IDs');
        $this->post(route('runs.store'), ['name' => 'Updated selected review', 'rerun_of' => $run->id, 'asset_ids' => [$outside->id]])->assertRedirect()->assertSessionHasNoErrors();
        $new = PrioritizationRun::where('name', 'Updated selected review')->firstOrFail();
        $this->assertSame([$asset->id], $new->prioritizationResults()->pluck('network_asset_id')->all());
        $this->assertSame($run->id, $new->decision_snapshot['rerun_of']);
        $this->assertSame($saved, $run->fresh()->decision_snapshot);
        $this->assertSame('current', app(RunStatusService::class)->inspect($new)['state']);
        $this->actingAs($this->institutionUser('management'))->get(route('runs.create', ['rerun_of' => $run->id]))->assertForbidden();
        $this->post(route('runs.store'), ['name' => 'Forbidden', 'rerun_of' => $run->id])->assertForbidden();
        $this->get(route('runs.show', $run))->assertOk()->assertDontSee('Run again');
    }

    public function test_coverage_exclusions_and_mixed_demo_labels_are_preserved_in_reports(): void
    {
        extract($this->fixture(true));
        $real = NetworkAsset::create(array_replace($this->assetValues(), ['asset_code' => 'REAL']));
        app(AssessmentService::class)->save($admin, ['network_asset_id' => $real->id, 'assessment_date' => today()->toDateString(), 'status' => 'completed', 'scores' => [$criterion->id => ['score' => 3]]]);
        NetworkAsset::create(array_replace($this->assetValues(), ['asset_code' => 'MISSING']));
        NetworkAsset::create(array_replace($this->assetValues(), ['asset_code' => 'RETIRED', 'status' => 'retired']));
        $run = app(PrioritizationService::class)->run($admin, 'Mixed review');
        $status = app(RunStatusService::class)->inspect($run);
        $this->assertSame(4, $status['coverage']['candidates']);
        $this->assertSame(3, $status['coverage']['eligible']);
        $this->assertSame(2, $status['coverage']['ranked']);
        $this->assertSame(2, $status['coverage']['skipped']);
        $this->assertEqualsWithDelta(200 / 3, $status['coverage']['percentage'], 1e-9);
        $this->assertSame('mixed', $status['provenance']['kind']);
        $this->assertSame(1, $status['provenance']['demo']);
        $this->get(route('reports.show', ['rankings', 'run_id' => $run->id]))->assertOk()->assertSee('MIXED DATA')->assertSee('2 / 3')->assertSee('66.7%');
        $asset->update(['name' => 'Renamed asset', 'asset_code' => 'RENAMED']);
        $this->assertSame('mixed', app(DemoDataService::class)->run($run)['kind']);
        $this->get(route('reports.show', 'inventory'))->assertOk()->assertSee('MIXED DATA');
    }

    public function test_legacy_run_is_not_falsely_declared_current_and_detects_demo_markers(): void
    {
        extract($this->fixture(true));
        $asset->update(['asset_code' => 'DEMO-LEGACY']);
        $run = app(PrioritizationService::class)->run($admin, 'Legacy demo');
        $snapshot = $run->decision_snapshot;
        unset($snapshot['input_fingerprint'],$snapshot['coverage'],$snapshot['data_provenance']);
        $run->update(['decision_snapshot' => $snapshot]);
        $result = $run->prioritizationResults()->first();
        $assetSnapshot = $result->asset_snapshot;
        unset($assetSnapshot['is_demo']);
        $result->update(['asset_snapshot' => $assetSnapshot]);
        $status = app(RunStatusService::class)->inspect($run->fresh());
        $this->assertSame('unknown', $status['state']);
        $this->assertTrue($status['legacy']);
        $this->assertSame('demo', $status['provenance']['kind']);
        $this->assertSame($snapshot, $run->fresh()->decision_snapshot);
        $this->get(route('runs.show', $run))->assertOk()->assertSee('currentness not fully verifiable')->assertSee('DEMONSTRATION DATA');
    }

    public function test_old_assessment_age_is_separate_from_input_changes(): void
    {
        extract($this->fixture());
        $assessment->update(['assessment_date' => today()->subYears(2)]);
        $run = app(PrioritizationService::class)->run($admin, 'Old evidence');
        $status = app(RunStatusService::class)->inspect($run);
        $this->assertSame('current', $status['state']);
        $this->assertSame(1, $status['overdue_assessments']);
    }

    public function test_all_asset_scope_detects_new_assets_and_eligibility_changes(): void
    {
        extract($this->fixture());
        $run = app(PrioritizationService::class)->run($admin, 'Whole inventory');
        $new = NetworkAsset::create(array_replace($this->assetValues(), ['asset_code' => 'NEW']));
        $status = app(RunStatusService::class)->inspect($run);
        $this->assertSame('changed', $status['state']);
        $this->assertSame(2, $status['current']['eligible_count']);
        $this->assertSame(1, $status['coverage']['eligible']);
        $new->delete();
        $this->assertSame('current', app(RunStatusService::class)->inspect($run)['state']);
        $asset->update(['status' => 'retired']);
        $status = app(RunStatusService::class)->inspect($run);
        $this->assertSame('changed',$status['state']);
        $this->assertSame(0,$status['current']['eligible_count']);
    }
}
