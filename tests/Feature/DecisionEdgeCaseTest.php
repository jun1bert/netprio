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

class DecisionEdgeCaseTest extends TestCase
{
    use BuildsNetpriorRecords, RefreshDatabase;

    public function test_criterion_changes_require_revalidation_and_preserve_old_results(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $a = Criterion::create(['code' => 'A', 'name' => 'Performance', 'direction' => 'benefit']);
        $b = Criterion::create(['code' => 'B', 'name' => 'Condition', 'direction' => 'benefit']);
        app(AhpService::class)->save($admin, [$a->id.'_'.$b->id => 1]);
        $asset = NetworkAsset::create($this->assetValues());
        $assessment = Assessment::create(['network_asset_id' => $asset->id, 'assessed_by' => $admin->id, 'assessment_date' => today(), 'status' => 'completed']);
        $assessment->assessmentScores()->create(['criterion_id' => $a->id, 'score' => 5]);
        $assessment->assessmentScores()->create(['criterion_id' => $b->id, 'score' => 1]);
        $old = app(PrioritizationService::class)->run($admin, 'Original');
        $this->assertSame(50.0, $old->prioritizationResults()->first()->final_score);
        $values = ['code' => 'B', 'name' => 'Condition', 'direction' => 'cost', 'display_order' => 1, 'is_active' => 1];
        $this->put(route('criteria.update', $b->id), $values)->assertSessionHasErrors('direction');
        $values['direction'] = 'benefit';
        $values['is_active'] = 0;
        $this->put(route('criteria.update', $b->id), $values)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('runs.store'), ['name' => 'Stale'])->assertSessionHasErrors('ahp');
        app(AhpService::class)->save($admin, []);
        $new = app(PrioritizationService::class)->run($admin, 'Reduced criteria');
        $this->assertSame(100.0, $new->prioritizationResults()->first()->final_score);
        $this->assertSame(50.0, $old->prioritizationResults()->first()->final_score);
        $this->assertCount(2, $old->fresh()->decision_snapshot['criteria']);
        $c = Criterion::create(['code' => 'C', 'name' => 'New security criterion', 'direction' => 'benefit']);
        app(AhpService::class)->save($admin, [$a->id.'_'.$c->id => 1]);
        $this->post(route('runs.store'), ['name' => 'Incomplete assessment'])->assertSessionHasErrors('assets');
        $this->assertDatabaseCount('prioritization_runs', 2);
        $this->delete(route('criteria.destroy', $a->id))->assertSessionHasErrors('record');
    }

    public function test_draft_cannot_move_to_another_asset_or_accept_out_of_range_scores(): void
    {
        $actor = $this->institutionUser('evaluator');
        $this->actingAs($actor);
        $a = Criterion::create(['code' => 'A', 'name' => 'A', 'direction' => 'benefit']);
        $asset = NetworkAsset::create($this->assetValues());
        $other = NetworkAsset::create(array_replace($this->assetValues(), ['asset_code' => 'SW-002']));
        $assessment = Assessment::create(['network_asset_id' => $asset->id, 'assessed_by' => $actor->id, 'assessment_date' => today()]);
        $values = ['network_asset_id' => $other->id, 'assessment_date' => today()->toDateString(), 'status' => 'draft'];
        $this->put(route('assessments.update', $assessment), $values)->assertSessionHasErrors('network_asset_id');
        $values['network_asset_id'] = $asset->id;
        $values['scores'] = [$a->id => ['score' => 6]];
        $this->put(route('assessments.update', $assessment), $values)->assertSessionHasErrors('scores.'.$a->id.'.score');
        $this->assertDatabaseCount('assessment_scores', 0);
    }

    public function test_date_filters_accept_a_to_date_without_a_from_date_and_historical_drafts_are_private(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $draft = PrioritizationRun::create(['name' => 'Draft only', 'created_by' => $admin->id]);
        $this->get(route('reports.show', ['inventory', 'to' => today()->toDateString()]))->assertOk();
        $this->actingAs($this->institutionUser('management'))->get(route('runs.show', $draft))->assertForbidden();
        $this->get(route('reports.show', ['rankings', 'run_id' => $draft->id]))->assertNotFound();
    }

    public function test_account_manager_cannot_remove_the_only_active_administrator(): void
    {
        $admin = $this->institutionUser();
        $operator = $this->institutionUser('operator');
        $operator->role->update(['permissions' => ['manage-users']]);
        $this->actingAs($operator);
        $this->put(route('users.update', $admin), ['name' => $admin->name, 'email' => $admin->email, 'role_id' => $admin->role_id, 'is_active' => 0])->assertSessionHasErrors('role_id');
        $this->assertTrue($admin->fresh()->is_active);
    }
}
