<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssetType;
use App\Models\AuditLog;
use App\Models\Criterion;
use App\Models\Location;
use App\Models\NetworkAsset;
use App\Models\PairwiseComparison;
use App\Models\PrioritizationResult;
use App\Models\PrioritizationRun;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NetpriorFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function records(): array
    {
        $user = User::factory()->create();
        $location = Location::create(['name' => 'Library']);
        $type = AssetType::create(['name' => 'Switch']);
        $asset = NetworkAsset::create([
            'asset_type_id' => $type->id, 'location_id' => $location->id,
            'asset_code' => 'SW-001', 'name' => 'Library switch', 'acquisition_cost' => '1250.50',
        ]);
        $criterion = Criterion::create(['code' => 'SEC', 'name' => 'Security risk', 'direction' => 'benefit']);
        $assessment = Assessment::create([
            'network_asset_id' => $asset->id, 'assessed_by' => $user->id,
            'assessment_date' => '2026-10-27', 'status' => 'completed',
        ]);

        return compact('user', 'location', 'type', 'asset', 'criterion', 'assessment');
    }

    public function test_relationships_casts_and_historical_snapshots(): void
    {
        extract($this->records());
        $role = Role::create(['code' => 'evaluator', 'name' => 'ICT / Network Evaluator']);
        $user->role()->associate($role)->save();
        $score = AssessmentScore::create([
            'assessment_id' => $assessment->id, 'criterion_id' => $criterion->id,
            'raw_value' => 92, 'raw_unit' => '%', 'score' => 4.5,
        ]);
        $snapshot = ['criteria' => [['name' => 'Security risk', 'direction' => 'benefit', 'weight' => 1.0]]];
        $run = PrioritizationRun::create([
            'name' => 'Integrity test', 'created_by' => $user->id, 'status' => 'completed',
            'completed_at' => now(), 'consistency_index' => 0, 'consistency_ratio' => 0,
            'decision_snapshot' => $snapshot,
        ]);
        $result = PrioritizationResult::create([
            'prioritization_run_id' => $run->id, 'network_asset_id' => $asset->id,
            'assessment_id' => $assessment->id, 'final_score' => 87.123456789,
            'rank' => 1, 'priority_level' => 'critical', 'recommendation' => 'Review for upgrade.',
            'asset_snapshot' => ['name' => $asset->name],
            'score_breakdown' => [['score' => 4.5, 'weight' => 1.0]],
        ]);
        $this->assertTrue($user->role->is($role));
        $this->assertTrue($role->users->first()->is($user));
        $this->assertTrue($asset->assetType->is($type));
        $this->assertTrue($asset->location->is($location));
        $this->assertTrue($location->networkAssets->first()->is($asset));
        $this->assertTrue($type->networkAssets->first()->is($asset));
        $this->assertTrue($assessment->networkAsset->is($asset));
        $this->assertTrue($assessment->evaluator->is($user));
        $this->assertTrue($assessment->assessmentScores->first()->criterion->is($criterion));
        $this->assertTrue($score->assessment->is($assessment));
        $this->assertTrue($criterion->assessmentScores->first()->is($score));
        $this->assertTrue($user->assessments->first()->is($assessment));
        $this->assertTrue($user->prioritizationRuns->first()->creator->is($user));
        $this->assertTrue($run->prioritizationResults->first()->is($result));
        $this->assertTrue($result->prioritizationRun->is($run));
        $this->assertTrue($result->networkAsset->is($asset));
        $this->assertTrue($result->assessment->is($assessment));
        $this->assertTrue($asset->prioritizationResults->first()->is($result));
        $this->assertTrue($assessment->prioritizationResults->first()->is($result));
        $this->assertSame('1250.50', $asset->fresh()->acquisition_cost);
        $this->assertSame('2026-10-27', $assessment->fresh()->assessment_date->toDateString());
        $this->assertSame(4.5, $score->fresh()->score);
        $this->assertEqualsWithDelta(87.123456789, $result->fresh()->final_score, 1e-10);
        $criterion->update(['name' => 'Renamed', 'is_active' => false]);
        $asset->update(['name' => 'Renamed asset']);
        $this->assertEquals($snapshot, $run->fresh()->decision_snapshot);
        $this->assertSame('Library switch', $result->fresh()->asset_snapshot['name']);
        $this->assertCount(0, Criterion::active()->get());
        $this->assertCount(1, Location::active()->get());
    }

    public function test_latest_completed_assessment_uses_date_then_id_and_supports_eager_loading(): void
    {
        extract($this->records());
        $values = [
            'network_asset_id' => $asset->id, 'assessed_by' => $user->id,
            'assessment_date' => '2026-10-28', 'status' => 'completed',
        ];
        Assessment::create($values);
        $latest = Assessment::create($values);
        // Higher IDs with older dates or draft status must not win.
        Assessment::create(array_replace($values, ['assessment_date' => '2026-10-26']));
        Assessment::create(array_replace($values, ['assessment_date' => '2026-10-29', 'status' => 'draft']));
        $unassessed = NetworkAsset::create([
            'asset_type_id' => $type->id, 'location_id' => $location->id,
            'asset_code' => 'SW-002', 'name' => 'Unassessed switch',
        ]);
        $draftOnly = NetworkAsset::create([
            'asset_type_id' => $type->id, 'location_id' => $location->id,
            'asset_code' => 'SW-003', 'name' => 'Draft only switch',
        ]);
        Assessment::create(array_replace($values, ['network_asset_id' => $draftOnly->id, 'status' => 'draft']));

        $this->assertTrue($asset->latestCompletedAssessment->is($latest));
        $assets = NetworkAsset::with('latestCompletedAssessment')->get()->keyBy('id');
        $this->assertTrue($assets[$asset->id]->relationLoaded('latestCompletedAssessment'));
        $this->assertTrue($assets[$asset->id]->latestCompletedAssessment->is($latest));
        $this->assertNull($assets[$unassessed->id]->latestCompletedAssessment);
        $this->assertNull($assets[$draftOnly->id]->latestCompletedAssessment);
        $this->assertCount(4, Assessment::completed()->get());
    }

    public function test_completed_run_scope_excludes_drafts(): void
    {
        $user = User::factory()->create();
        PrioritizationRun::create(['name' => 'Draft', 'created_by' => $user->id]);
        $completed = PrioritizationRun::create([
            'name' => 'Completed', 'created_by' => $user->id,
            'status' => 'completed', 'completed_at' => now(),
        ]);

        $this->assertSame([$completed->id], PrioritizationRun::completed()->pluck('id')->all());
        $this->assertNotNull($completed->fresh()->completed_at);
    }

    public function test_pairwise_relationships_and_reciprocal_storage(): void
    {
        extract($this->records());
        $second = Criterion::create(['code' => 'PERF', 'name' => 'Performance', 'direction' => 'cost']);
        $comparison = PairwiseComparison::create([
            'criterion_a_id' => $criterion->id, 'criterion_b_id' => $second->id,
            'comparison_value' => 1 / 3, 'created_by' => $user->id,
        ]);
        $this->assertTrue($comparison->criterionA->is($criterion));
        $this->assertTrue($comparison->criterionB->is($second));
        $this->assertTrue($comparison->creator->is($user));
        $this->assertTrue($criterion->comparisonsAsA->first()->is($comparison));
        $this->assertTrue($second->comparisonsAsB->first()->is($comparison));
        $this->assertTrue($user->pairwiseComparisons->first()->is($comparison));
        $this->assertEqualsWithDelta(1 / 3, $comparison->fresh()->comparison_value, 1e-14);
    }

    public static function invalidRecords(): array
    {
        return array_map(fn ($value) => [$value], [
            'duplicate_asset', 'duplicate_score', 'score_below_range', 'score_above_range',
            'self_comparison', 'reverse_comparison', 'duplicate_comparison', 'invalid_ratio',
            'delete_location', 'delete_asset', 'delete_evaluator', 'delete_criterion', 'negative_cost',
        ]);
    }

    #[DataProvider('invalidRecords')]
    public function test_database_rejects_invalid_or_destructive_changes(string $case): void
    {
        extract($this->records());
        $second = Criterion::create(['code' => 'PERF', 'name' => 'Performance', 'direction' => 'cost']);
        $score = ['assessment_id' => $assessment->id, 'criterion_id' => $criterion->id, 'score' => 3];
        $pair = ['criterion_a_id' => $criterion->id, 'criterion_b_id' => $second->id, 'comparison_value' => 3, 'created_by' => $user->id];
        AssessmentScore::create($score);
        PairwiseComparison::create($pair);
        $this->expectException(QueryException::class);
        match ($case) {
            'duplicate_asset' => NetworkAsset::create($asset->getAttributes()),
            'duplicate_score' => AssessmentScore::create($score),
            'score_below_range' => AssessmentScore::where('assessment_id', $assessment->id)->update(['score' => 0]),
            'score_above_range' => AssessmentScore::where('assessment_id', $assessment->id)->update(['score' => 5.1]),
            'self_comparison' => PairwiseComparison::create(array_replace($pair, ['criterion_b_id' => $criterion->id])),
            'reverse_comparison' => PairwiseComparison::create(array_replace($pair, ['criterion_a_id' => $second->id, 'criterion_b_id' => $criterion->id])),
            'duplicate_comparison' => PairwiseComparison::create($pair),
            'invalid_ratio' => PairwiseComparison::query()->update(['comparison_value' => 10]),
            'delete_location' => $location->delete(),
            'delete_asset' => $asset->delete(),
            'delete_evaluator' => $user->delete(),
            'delete_criterion' => $criterion->delete(),
            'negative_cost' => $asset->update(['acquisition_cost' => -1]),
        };
    }

    public function test_audit_survives_account_deletion_and_role_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['code' => 'administrator', 'name' => 'Administrator']);
        $user->fill(['name' => 'Updated name', 'role_id' => $role->id])->save();
        $this->assertNull($user->fresh()->role_id);
        $log = AuditLog::create([
            'user_id' => $user->id, 'action' => 'test', 'entity_type' => 'user',
            'entity_id' => $user->id, 'description' => 'Test audit retention', 'metadata' => ['actor_name' => $user->name],
        ]);
        $this->assertTrue($log->user->is($user));
        $this->assertTrue($user->auditLogs->first()->is($log));
        $user->delete();
        $this->assertNull($log->fresh()->user_id);
        $this->assertSame('Updated name', $log->fresh()->metadata['actor_name']);
        $this->assertNotNull($log->fresh()->created_at);
    }
}
