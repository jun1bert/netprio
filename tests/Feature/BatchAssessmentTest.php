<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Services\AssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsNetpriorRecords;
use Tests\TestCase;

class BatchAssessmentTest extends TestCase
{
    use BuildsNetpriorRecords,RefreshDatabase;

    private function setupBatch(): array
    {
        $actor = $this->institutionUser();
        $this->actingAs($actor);
        $criterion = Criterion::create(['code' => 'PERF', 'name' => 'Performance', 'direction' => 'benefit']);
        $second = Criterion::create(['code' => 'COST', 'name' => 'Cost', 'direction' => 'cost']);
        $assets = collect(['A-001', 'A-002'])->map(fn ($code) => NetworkAsset::create(array_replace($this->assetValues(), ['asset_code' => $code])));

        return compact('actor', 'criterion', 'second', 'assets');
    }

    public function test_a_late_stale_row_rolls_back_earlier_row_writes(): void
    {
        extract($this->setupBatch());
        $draft = app(AssessmentService::class)->save($actor, ['network_asset_id' => $assets[1]->id, 'assessment_date' => today()->toDateString(), 'status' => 'draft', 'scores' => [$criterion->id => ['score' => 2]]]);
        $token = $this->token();
        app(AssessmentService::class)->save($actor, ['network_asset_id' => $assets[1]->id, 'assessment_date' => today()->toDateString(), 'status' => 'draft', 'scores' => [$criterion->id => ['score' => 4]]], $draft);
        $auditCount = AuditLog::count();
        $this->post(route('assessments.batch.store'), ['batch_token' => $token, 'action' => 'draft', 'rows' => [$assets[0]->id => $this->row([$criterion->id => 5]), $assets[1]->id => $this->row([$criterion->id => 3])]])->assertSessionHasErrors('rows.'.$assets[1]->id.'.selected');
        $this->assertDatabaseCount('assessments', 1);
        $this->assertDatabaseMissing('assessments', ['network_asset_id' => $assets[0]->id]);
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_evaluators_cannot_replace_another_evaluators_draft(): void
    {
        extract($this->setupBatch());
        $otherDraft = app(AssessmentService::class)->save($actor, ['network_asset_id' => $assets[0]->id, 'assessment_date' => today()->toDateString(), 'status' => 'draft', 'scores' => [$criterion->id => ['score' => 2]]]);
        $evaluator = $this->institutionUser('evaluator');
        $this->actingAs($evaluator);
        $token = $this->token();
        $this->post(route('assessments.batch.store'), ['batch_token' => $token, 'action' => 'draft', 'rows' => [$assets[0]->id => $this->row([$criterion->id => 5])]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('assessments', 2);
        $this->assertSame($actor->id, $otherDraft->fresh()->assessed_by);
        $this->assertEquals(2, $otherDraft->assessmentScores()->first()->score);
        $this->assertDatabaseHas('assessments', ['network_asset_id' => $assets[0]->id, 'assessed_by' => $evaluator->id]);
    }

    public function test_batch_pages_are_bounded_and_expired_pages_cannot_save(): void
    {
        extract($this->setupBatch());
        foreach (range(3, 23) as $number) {
            NetworkAsset::create(array_replace($this->assetValues(), ['asset_code' => sprintf('A-%03d', $number)]));
        }
        $page = $this->get(route('assessments.batch'))->assertOk();
        $this->assertCount(20, $page->viewData('assets'));
        $this->assertCount(3, $this->get(route('assessments.batch', ['page' => 2]))->viewData('assets'));
        $token = $page->viewData('token');
        $this->travel(121)->minutes();
        $this->post(route('assessments.batch.store'), ['batch_token' => $token, 'action' => 'draft', 'rows' => [$assets[0]->id => $this->row([])]])->assertSessionHasErrors('batch_token');
        $this->assertDatabaseCount('assessments', 0);
    }

    private function token(): string
    {
        return $this->get(route('assessments.batch'))->assertOk()->viewData('token');
    }

    private function row(array $scores): array
    {
        return ['selected' => 1, 'assessment_date' => today()->toDateString(), 'scores' => $scores, 'remarks' => 'Batch review'];
    }

    public function test_selected_rows_create_separate_completed_assessments_and_replay_is_rejected(): void
    {
        extract($this->setupBatch());
        $token = $this->token();
        $payload = ['batch_token' => $token, 'action' => 'completed', 'rows' => $assets->mapWithKeys(fn ($asset) => [$asset->id => $this->row([$criterion->id => 4, $second->id => 2.5])])->all()];
        $this->post(route('assessments.batch.store'), $payload)->assertRedirect(route('assessments.batch'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('assessments', 2);
        $this->assertDatabaseCount('assessment_scores', 4);
        $this->assertSame(2, Assessment::completed()->count());
        $this->assertSame(2, Assessment::distinct()->count('network_asset_id'));
        $this->post(route('assessments.batch.store'), $payload)->assertSessionHasErrors('batch_token');
        $this->assertDatabaseCount('assessments', 2);
    }

    public function test_incomplete_completion_invalid_ranges_and_future_dates_save_nothing(): void
    {
        extract($this->setupBatch());
        $token = $this->token();
        $rows = [$assets[0]->id => $this->row([$criterion->id => 4, $second->id => 2]), $assets[1]->id => $this->row([$criterion->id => 3])];
        $this->post(route('assessments.batch.store'), ['batch_token' => $token, 'action' => 'completed', 'rows' => $rows])->assertSessionHasErrors('rows.'.$assets[1]->id.'.scores.'.$second->id);
        $this->assertDatabaseCount('assessments', 0);
        $rows[$assets[1]->id]['scores'][$second->id] = 6;
        $this->post(route('assessments.batch.store'), ['batch_token' => $token, 'action' => 'draft', 'rows' => $rows])->assertSessionHasErrors('rows.'.$assets[1]->id.'.scores.'.$second->id);
        $rows[$assets[1]->id]['scores'][$second->id] = 3;
        $rows[$assets[1]->id]['assessment_date'] = today()->addDay()->toDateString();
        $this->post(route('assessments.batch.store'), ['batch_token' => $token, 'action' => 'draft', 'rows' => $rows])->assertSessionHasErrors('rows.'.$assets[1]->id.'.assessment_date');
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_partial_drafts_resume_and_complete_without_losing_evidence_or_creating_duplicates(): void
    {
        extract($this->setupBatch());
        $draft = app(AssessmentService::class)->save($actor, ['network_asset_id' => $assets[0]->id, 'assessment_date' => today()->subDay()->toDateString(), 'status' => 'draft', 'scores' => [$criterion->id => ['score' => 3, 'raw_value' => 82, 'raw_unit' => '%', 'remarks' => 'Measured load']]]);
        $token = $this->token();
        $this->post(route('assessments.batch.store'), ['batch_token' => $token, 'action' => 'draft', 'rows' => [$assets[0]->id => $this->row([$criterion->id => 4, $second->id => null]), $assets[1]->id => ['selected' => 0, 'scores' => [$criterion->id => 999]]]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('assessments', 1);
        $this->assertSame(82.0, (float) $draft->assessmentScores()->first()->raw_value);
        $this->assertSame('Measured load', $draft->assessmentScores()->first()->remarks);
        $token = $this->token();
        $this->post(route('assessments.batch.store'), ['batch_token' => $token, 'action' => 'completed', 'rows' => [$assets[0]->id => $this->row([$criterion->id => 5, $second->id => 2])]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('assessments', 1);
        $this->assertSame('completed', $draft->fresh()->status);
        $this->assertSame('%', $draft->assessmentScores()->where('criterion_id', $criterion->id)->first()->raw_unit);
    }

    public function test_stale_drafts_and_criteria_changes_cannot_overwrite_saved_work(): void
    {
        extract($this->setupBatch());
        $draft = app(AssessmentService::class)->save($actor, ['network_asset_id' => $assets[0]->id, 'assessment_date' => today()->toDateString(), 'status' => 'draft', 'scores' => [$criterion->id => ['score' => 2]]]);
        $token = $this->token();
        app(AssessmentService::class)->save($actor, ['network_asset_id' => $assets[0]->id, 'assessment_date' => today()->toDateString(), 'status' => 'draft', 'scores' => [$criterion->id => ['score' => 4]]], $draft);
        $payload = ['batch_token' => $token, 'action' => 'draft', 'rows' => [$assets[0]->id => $this->row([$criterion->id => 3])]];
        $this->post(route('assessments.batch.store'), $payload)->assertSessionHasErrors('rows.'.$assets[0]->id.'.selected');
        $this->assertEquals(4, $draft->assessmentScores()->first()->score);
        $payload['batch_token'] = $this->token();
        $criterion->update(['description' => 'Changed scoring interpretation']);
        $this->post(route('assessments.batch.store'), $payload)->assertSessionHasErrors('rows');
        $this->assertEquals(4, $draft->assessmentScores()->first()->score);
    }

    public function test_group_scope_retirement_and_authorization_are_enforced(): void
    {
        extract($this->setupBatch());
        $token = $this->token();
        $outside = NetworkAsset::create(array_replace($this->assetValues(), ['asset_code' => 'OUTSIDE']));
        $payload = ['batch_token' => $token, 'action' => 'draft', 'rows' => [$outside->id => $this->row([])]];
        $this->post(route('assessments.batch.store'), $payload)->assertSessionHasErrors('rows');
        $assets[0]->update(['status' => 'retired']);
        $payload['rows'] = [$assets[0]->id => $this->row([])];
        $this->post(route('assessments.batch.store'), $payload)->assertSessionHasErrors('rows.'.$assets[0]->id.'.selected');
        $this->assertDatabaseCount('assessments', 0);
        $this->actingAs($this->institutionUser('management'))->get(route('assessments.batch'))->assertForbidden();
        $this->post(route('assessments.batch.store'), $payload)->assertForbidden();
    }

    public function test_draft_completed_in_another_tab_remains_immutable_and_evidence_cannot_be_silently_cleared(): void
    {
        extract($this->setupBatch());
        $draft = app(AssessmentService::class)->save($actor, ['network_asset_id' => $assets[0]->id, 'assessment_date' => today()->toDateString(), 'status' => 'draft', 'scores' => [$criterion->id => ['score' => 3, 'raw_value' => 0, 'raw_unit' => 'errors']]]);
        $token = $this->token();
        $this->post(route('assessments.batch.store'), ['batch_token' => $token, 'action' => 'draft', 'rows' => [$assets[0]->id => $this->row([$criterion->id => null])]])->assertSessionHasErrors('rows.'.$assets[0]->id.'.scores.'.$criterion->id);
        app(AssessmentService::class)->save($actor, ['network_asset_id' => $assets[0]->id, 'assessment_date' => today()->toDateString(), 'status' => 'completed', 'scores' => [$criterion->id => ['score' => 3], $second->id => ['score' => 2]]], $draft);
        $this->post(route('assessments.batch.store'), ['batch_token' => $token, 'action' => 'draft', 'rows' => [$assets[0]->id => $this->row([$criterion->id => 5])]])->assertSessionHasErrors('rows.'.$assets[0]->id.'.selected');
        $this->assertSame('completed', $draft->fresh()->status);
        $this->assertEquals(3, $draft->assessmentScores()->where('criterion_id', $criterion->id)->first()->score);
    }
}
