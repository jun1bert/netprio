<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Services\AhpService;
use App\Services\PrioritizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsNetpriorRecords;
use Tests\TestCase;

class ReportDashboardTest extends TestCase
{
    use BuildsNetpriorRecords, RefreshDatabase;

    public function test_all_report_types_handle_empty_data_and_validate_filters(): void
    {
        $this->actingAs($this->institutionUser());
        $this->get(route('reports.index'))->assertOk();
        foreach (['inventory', 'assessments', 'rankings', 'critical', 'weights', 'consistency', 'history'] as $type) {
            $this->get(route('reports.show', $type))->assertOk();
        }
        $this->get(route('reports.show', ['inventory', 'from' => '2026-10-27', 'to' => '2026-01-01']))->assertSessionHasErrors('to');
        $this->get(route('reports.show', 'unknown'))->assertNotFound();
    }

    public function test_dashboard_and_historical_reports_use_preserved_data(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $criterion = Criterion::create(['code' => 'SEC', 'name' => 'Security risk', 'direction' => 'benefit']);
        app(AhpService::class)->save($admin, []);
        $asset = NetworkAsset::create($this->assetValues());
        $assessment = Assessment::create(['network_asset_id' => $asset->id, 'assessed_by' => $admin->id, 'assessment_date' => today(), 'status' => 'completed']);
        $assessment->assessmentScores()->create(['criterion_id' => $criterion->id, 'score' => 5]);
        $run = app(PrioritizationService::class)->run($admin, 'Management review');
        $this->get(route('dashboard'))->assertOk()->assertSee('Management review')->assertSee('100.00');
        $asset->update(['name' => 'New source name']);
        foreach (['rankings', 'critical', 'weights', 'consistency', 'history'] as $type) {
            $this->get(route('reports.show', [$type, 'run_id' => $run->id]))->assertOk()->assertSee('Management review');
        }
        $this->get(route('reports.show', ['rankings', 'run_id' => $run->id]))->assertSee('Core switch')->assertDontSee('New source name');
        $this->actingAs($this->institutionUser('management'));
        $this->get(route('reports.show', 'rankings'))->assertOk();
        $this->get(route('reports.show', 'assessments'))->assertForbidden();
    }
}
