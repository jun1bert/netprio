<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssetType;
use App\Models\Criterion;
use App\Models\Location;
use App\Models\NetworkAsset;
use App\Models\PrioritizationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsNetpriorRecords;
use Tests\TestCase;

class EndToEndDecisionTest extends TestCase
{
    use BuildsNetpriorRecords, RefreshDatabase;

    public function test_institution_can_configure_assess_rank_and_review_via_http(): void
    {
        $admin = $this->institutionUser();
        $evaluator = $this->institutionUser('evaluator');
        $manager = $this->institutionUser('management');
        $this->actingAs($admin);
        $this->post(route('locations.store'), ['name' => 'Library', 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('asset-types.store'), ['name' => 'Switch', 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        foreach ([['PERF', 'Performance', 'benefit'], ['COST', 'Upgrade cost', 'cost']] as [$code,$name,$direction]) {
            $this->post(route('criteria.store'), compact('code', 'name', 'direction') + ['display_order' => 0, 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        }
        $criteria = Criterion::orderBy('id')->get();
        $this->post(route('ahp.store'), ['pairs' => [$criteria[0]->id.'_'.$criteria[1]->id => 3]])->assertRedirect()->assertSessionHasNoErrors();
        $values = ['asset_type_id' => AssetType::first()->id, 'location_id' => Location::first()->id, 'name' => 'Library distribution switch', 'asset_code' => 'SW-LIB-01', 'status' => 'active'];
        $this->post(route('assets.store'), $values)->assertRedirect()->assertSessionHasNoErrors();
        $asset = NetworkAsset::firstOrFail();
        $this->actingAs($evaluator);
        $this->post(route('assessments.store'), ['network_asset_id' => $asset->id, 'assessment_date' => today()->toDateString(), 'status' => 'completed',
            'scores' => [$criteria[0]->id => ['score' => 5, 'remarks' => 'Observed service degradation'], $criteria[1]->id => ['score' => 3, 'raw_value' => 10000, 'raw_unit' => 'local currency']]])->assertRedirect()->assertSessionHasNoErrors();
        $assessment = Assessment::firstOrFail();
        $this->get(route('assessments.show', $assessment))->assertOk();
        $this->actingAs($admin)->post(route('runs.store'), ['name' => 'Annual infrastructure review'])->assertRedirect()->assertSessionHasNoErrors();
        $run = PrioritizationRun::firstOrFail();
        $result = $run->prioritizationResults()->firstOrFail();
        // Performance weight .75 × 100 + cost weight .25 × 50 = 87.5.
        $this->assertEqualsWithDelta(87.5, $result->final_score, 1e-10);
        $this->assertSame('critical', $result->priority_level);
        $this->assertSame($assessment->id, $result->assessment_id);
        $this->actingAs($manager)->get(route('dashboard'))->assertOk()->assertSee('Annual infrastructure review');
        $this->get(route('runs.result', [$run, $result]))->assertOk()->assertSee('87.50')->assertSee('Performance')->assertDontSee('Observed service degradation');
        $this->get(route('reports.show', ['rankings', 'run_id' => $run->id]))->assertOk()->assertSee('SW-LIB-01');
        $this->get(route('assets.show', $asset))->assertOk()->assertSee('Annual infrastructure review');
        $this->put(route('assessments.update', $assessment), ['status' => 'draft'])->assertForbidden();
        $this->get(route('audit.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('audit.index'))->assertOk()->assertSee('prioritization.executed');
    }
}
