<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Criterion;
use App\Models\NetworkAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsNetpriorRecords;
use Tests\TestCase;

class AssessmentWorkflowTest extends TestCase
{
    use BuildsNetpriorRecords, RefreshDatabase;

    public function test_draft_completion_ownership_validation_and_historical_scores(): void
    {
        $evaluator = $this->institutionUser('evaluator');
        $this->actingAs($evaluator);
        $asset = NetworkAsset::create($this->assetValues());
        $criterion = Criterion::create(['code' => 'SEC', 'name' => 'Security', 'direction' => 'benefit']);
        $payload = ['network_asset_id' => $asset->id, 'assessment_date' => today()->toDateString(), 'status' => 'draft', 'assessed_by' => 999];
        $this->get(route('assessments.create'))->assertOk();
        $this->post(route('assessments.store'), $payload)->assertSessionHasNoErrors();
        $assessment = Assessment::firstOrFail();
        $this->assertSame($evaluator->id, $assessment->assessed_by);
        $this->get(route('assessments.edit', $assessment))->assertOk();
        $this->put(route('assessments.update', $assessment), array_replace($payload, ['status' => 'completed']))->assertSessionHasErrors('scores.'.$criterion->id.'.score');
        $this->assertSame('draft', $assessment->fresh()->status);
        $this->actingAs($this->institutionUser('evaluator'))->put(route('assessments.update', $assessment), $payload)->assertForbidden();
        $this->actingAs($evaluator);
        $payload['scores'] = [$criterion->id => ['score' => 4.5, 'raw_value' => 92, 'raw_unit' => '%', 'remarks' => 'Measured evidence']];
        $payload['status'] = 'completed';
        $this->put(route('assessments.update', $assessment), $payload)->assertSessionHasNoErrors();
        $this->assertSame('completed', $assessment->fresh()->status);
        $this->assertSame('Security', AssessmentScore::first()->criterion_snapshot['name']);
        $criterion->update(['name' => 'Renamed']);
        $this->get(route('assessments.show', $assessment))->assertOk()->assertSee('Security')->assertSee('Measured evidence');
        $this->put(route('assessments.update', $assessment), $payload)->assertForbidden();
        $this->delete(route('assessments.destroy', $assessment))->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'assessment.completed']);
    }

    public function test_completion_rejects_no_criteria_retired_assets_and_unknown_criterion_ids(): void
    {
        $this->actingAs($this->institutionUser('evaluator'));
        $asset = NetworkAsset::create($this->assetValues());
        $values = ['network_asset_id' => $asset->id, 'assessment_date' => today()->toDateString(), 'status' => 'completed'];
        $this->post(route('assessments.store'), $values)->assertSessionHasErrors('scores');
        $values['status'] = 'draft';
        $values['scores'] = [999 => ['score' => 3]];
        $this->post(route('assessments.store'), $values)->assertSessionHasErrors('scores');
        $asset->update(['status' => 'retired']);
        $this->post(route('assessments.store'), $values)->assertSessionHasErrors('network_asset_id');
        $this->assertDatabaseCount('assessments', 0);
    }
}
