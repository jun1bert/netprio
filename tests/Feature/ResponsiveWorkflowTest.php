<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssetType;
use App\Models\Criterion;
use App\Models\Location;
use App\Models\NetworkAsset;
use App\Services\AhpService;
use App\Services\PrioritizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsNetpriorRecords;
use Tests\TestCase;

class ResponsiveWorkflowTest extends TestCase
{
    use BuildsNetpriorRecords, RefreshDatabase;

    public function test_ranking_filters_and_sorting_use_historical_snapshot_values(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $criterion = Criterion::create(['code' => 'SEC', 'name' => 'Security', 'direction' => 'benefit']);
        app(AhpService::class)->save($admin, []);
        $base = $this->assetValues();
        $first = NetworkAsset::create(array_replace($base, ['name' => 'Zeta switch']));
        $second = NetworkAsset::create(array_replace($base, ['asset_code' => 'RTR-002', 'name' => 'Alpha router', 'location_id' => Location::create(['name' => 'South campus'])->id, 'asset_type_id' => AssetType::create(['name' => 'Router'])->id]));
        foreach ([[$first, 5], [$second, 3]] as [$asset,$value]) {
            $assessment = Assessment::create(['network_asset_id' => $asset->id, 'assessed_by' => $admin->id, 'assessment_date' => today(), 'status' => 'completed']);
            $assessment->assessmentScores()->create(['criterion_id' => $criterion->id, 'score' => $value]);
        }
        $run = app(PrioritizationService::class)->run($admin, 'Snapshot filter review');
        $first->location->update(['name' => 'Renamed source location']);
        $first->assetType->update(['name' => 'Renamed source type']);
        $this->get(route('runs.show', [$run, 'location' => 'Data center', 'asset_type' => 'Switch', 'priority' => 'critical']))
            ->assertOk()->assertViewHas('results', fn ($rows) => $rows->count() === 1 && $rows->first()->network_asset_id === $first->id);
        $this->get(route('runs.show', [$run, 'sort' => 'asset', 'direction' => 'asc']))
            ->assertOk()->assertViewHas('results', fn ($rows) => $rows->pluck('network_asset_id')->all() === [$second->id, $first->id]);
        $this->get(route('runs.show', [$run, 'sort' => 'score']))
            ->assertOk()->assertViewHas('results', fn ($rows) => $rows->pluck('network_asset_id')->all() === [$first->id, $second->id]);
        $this->get(route('runs.show', [$run, 'sort' => 'untrusted_column']))->assertSessionHasErrors('sort');
    }

    public function test_assessment_queue_link_matches_the_dashboard_including_overdue_assessments(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $base = $this->assetValues();
        $old = NetworkAsset::create($base);
        $missing = NetworkAsset::create(array_replace($base, ['asset_code' => 'SW-002']));
        $fresh = NetworkAsset::create(array_replace($base, ['asset_code' => 'SW-003']));
        $retired = NetworkAsset::create(array_replace($base, ['asset_code' => 'SW-004', 'status' => 'retired']));
        foreach ([[$old, today()->subYears(2)], [$fresh, today()]] as [$asset,$date]) {
            Assessment::create(['network_asset_id' => $asset->id, 'assessed_by' => $admin->id, 'assessment_date' => $date, 'status' => 'completed']);
        }
        $this->get(route('dashboard'))->assertOk()->assertViewHas('due', 2);
        $this->get(route('assets.index', ['assessment_status' => 'due']))->assertOk()
            ->assertViewHas('assets', fn ($rows) => $rows->pluck('id')->all() === [$old->id, $missing->id]);
    }
}
