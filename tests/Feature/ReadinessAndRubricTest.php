<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Models\PairwiseComparison;
use App\Services\AhpService;
use App\Services\AssessmentService;
use App\Services\ScoringRubricService;
use App\Services\WorkflowReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsNetpriorRecords;
use Tests\TestCase;

class ReadinessAndRubricTest extends TestCase
{
    use BuildsNetpriorRecords, RefreshDatabase;

    public function test_empty_setup_has_clear_blockers_and_no_false_readiness(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $state = app(WorkflowReadinessService::class)->for($admin);
        $this->assertFalse($state['can_run']);
        $this->assertSame(0, $state['assessed']);
        $this->assertSame(0, $state['completed']);
        $this->assertSame(route('assets.create'), $state['next']['url']);
        $this->get(route('dashboard'))->assertOk()->assertSee('Decision workflow')->assertSee('Needs attention');
    }

    public function test_partial_coverage_matches_engine_requirements_and_filter_even_with_newer_drafts(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $a = Criterion::create(['code' => 'A', 'name' => 'A', 'direction' => 'benefit']);
        $b = Criterion::create(['code' => 'B', 'name' => 'B', 'direction' => 'cost']);
        app(AhpService::class)->save($admin, [$a->id.'_'.$b->id => 3]);
        $base = $this->assetValues();
        $assets = [];
        foreach (['READY', 'PARTIAL', 'DRAFT', 'RETIRED'] as $code) {
            $assets[$code] = NetworkAsset::create(array_replace($base, ['asset_code' => $code, 'status' => $code === 'RETIRED' ? 'retired' : 'active']));
        }
        foreach (['READY', 'PARTIAL', 'RETIRED'] as $code) {
            $assessment = Assessment::create(['network_asset_id' => $assets[$code]->id, 'assessed_by' => $admin->id, 'assessment_date' => today(), 'status' => 'completed']);
            $assessment->assessmentScores()->create(['criterion_id' => $a->id, 'score' => 4]);
            if ($code !== 'PARTIAL') {
                $assessment->assessmentScores()->create(['criterion_id' => $b->id, 'score' => 2]);
            }
        }
        Assessment::create(['network_asset_id' => $assets['READY']->id, 'assessed_by' => $admin->id, 'assessment_date' => today(), 'status' => 'draft']);
        Assessment::create(['network_asset_id' => $assets['DRAFT']->id, 'assessed_by' => $admin->id, 'assessment_date' => today(), 'status' => 'draft']);
        $state = app(WorkflowReadinessService::class)->for($admin);
        $this->assertTrue($state['can_run']);
        $this->assertSame(3, $state['eligible']);
        $this->assertSame(1, $state['assessed']);
        $this->assertSame(2, $state['missing']);
        $this->assertStringContainsString('partial coverage', $state['note']);
        $this->assertSame('attention', $state['steps'][1]['state']);
        $this->get($state['next']['url'])->assertOk()->assertViewHas('assets', fn ($rows) => $rows->pluck('asset_code')->all() === ['DRAFT', 'PARTIAL']);
        $a->update(['weight' => null]);
        $state = app(WorkflowReadinessService::class)->for($admin);
        $this->assertFalse($state['can_run']);
        $this->assertSame('blocked', $state['steps'][2]['state']);
        $this->assertStringContainsString('accepted', $state['steps'][2]['detail']);
    }

    public function test_management_and_dashboard_only_roles_receive_only_authorized_links(): void
    {
        $manager = $this->institutionUser('management');
        $this->actingAs($manager);
        $state = app(WorkflowReadinessService::class)->for($manager);
        $this->assertSame(route('assets.index'), $state['next']['url']);
        foreach ($state['steps'] as $step) {
            $this->assertNotContains($step['action']['url'], [route('assets.create'), route('assessments.create'), route('runs.create'), route('ahp.index')]);
        }
        $observer = $this->institutionUser('observer');
        $observer->role->update(['permissions' => ['view-dashboard']]);
        $state = app(WorkflowReadinessService::class)->for($observer->fresh());
        foreach ($state['steps'] as $step) {
            $this->assertNull($step['action']['url']);
        }
        $this->assertNull($state['next']['url']);
        $this->actingAs($observer->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_suggested_guidance_is_criterion_specific_and_direction_safe(): void
    {
        $service = app(ScoringRubricService::class);
        $performance = $service->for(new Criterion(['code' => 'PERFORMANCE', 'direction' => 'benefit']));
        $cost = $service->for(new Criterion(['code' => 'COST', 'direction' => 'cost']));
        $this->assertSame('suggested', $performance['source']);
        $this->assertCount(5, $performance['levels']);
        $this->assertStringContainsString('performance deficiency', $performance['levels'][5]);
        $this->assertStringContainsString('Low upgrade financial burden', $cost['levels'][1]);
        $this->assertStringContainsString('Very high financial burden', $cost['levels'][5]);
        $this->assertSame('general', $service->for(new Criterion(['code' => 'COST', 'direction' => 'benefit']))['source']);
        $this->assertSame('general', $service->for(new Criterion(['code' => 'CUSTOM', 'direction' => 'cost']))['source']);
    }

    public function test_inconsistent_judgments_are_a_readiness_blocker_without_rewriting_them(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $criteria = collect(['A', 'B', 'C'])->map(fn ($code) => Criterion::create(['code' => $code, 'name' => $code, 'direction' => 'benefit']));
        [$a,$b,$c] = $criteria->all();
        app(AhpService::class)->save($admin, [$a->id.'_'.$b->id => 9, $a->id.'_'.$c->id => 1 / 9, $b->id.'_'.$c->id => 9]);
        $before = PairwiseComparison::pluck('comparison_value', 'id')->all();
        $state = app(WorkflowReadinessService::class)->for($admin);
        $this->assertFalse($state['can_run']);
        $this->assertGreaterThan(0.1, $state['consistency_ratio']);
        $this->assertStringContainsString('exceeds 0.10', $state['steps'][2]['detail']);
        $this->assertSame($before, PairwiseComparison::pluck('comparison_value', 'id')->all());
    }

    public function test_custom_rubrics_validate_and_are_preserved_with_completed_assessment_scores(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $criterion = Criterion::create(['code' => 'PERFORMANCE', 'name' => 'Performance', 'direction' => 'benefit']);
        $values = ['code' => 'PERFORMANCE', 'name' => 'Performance', 'direction' => 'benefit', 'display_order' => 0, 'is_active' => 1];
        $this->put(route('criteria.update', $criterion->id), $values + ['rubric' => [1 => 'Only one description']])->assertSessionHasErrors('rubric');
        $this->assertNull($criterion->fresh()->rubric);
        $guide = array_combine(range(1, 5), array_map(fn ($n) => 'Institutional example '.$n, range(1, 5)));
        $this->put(route('criteria.update', $criterion->id), $values + ['rubric' => $guide])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('institution', app(ScoringRubricService::class)->for($criterion->fresh())['source']);
        $asset = NetworkAsset::create($this->assetValues());
        $assessment = app(AssessmentService::class)->save($admin, ['network_asset_id' => $asset->id, 'assessment_date' => today()->toDateString(), 'status' => 'completed', 'scores' => [$criterion->id => ['score' => 4]]]);
        $snapshot = $assessment->assessmentScores()->first()->criterion_snapshot;
        $this->assertSame($guide, $snapshot['rubric']);
        $this->assertSame('institution', $snapshot['rubric_source']);
        $this->assertSame(4.0, $assessment->assessmentScores()->first()->score);
        $guide[4] = 'Changed rubric for future assessments';
        $this->put(route('criteria.update', $criterion->id), $values + ['rubric' => $guide])->assertSessionHasNoErrors();
        $this->assertSame('Institutional example 4', $assessment->assessmentScores()->first()->criterion_snapshot['rubric'][4]);
        $this->get(route('assessments.show', $assessment))->assertOk()->assertSee('Institutional example 4')->assertDontSee('Changed rubric for future assessments');
        $this->get(route('assessments.create', ['asset' => $asset->id]))->assertOk()->assertSee('Changed rubric for future assessments');
        $this->put(route('criteria.update', $criterion->id), $values + ['rubric' => array_fill(1, 5, '')])->assertSessionHasNoErrors();
        $this->assertNull($criterion->fresh()->rubric);
        $this->assertSame('suggested', app(ScoringRubricService::class)->for($criterion->fresh())['source']);
    }
}
