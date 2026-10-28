<?php

namespace App\Services;

use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Models\PrioritizationRun;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RunStatusService
{
    public function inspect(PrioritizationRun $run): array
    {
        $snapshot = $run->decision_snapshot ?? [];
        $results = $run->prioritizationResults()->get();
        $skipped = $snapshot['skipped_assets'] ?? [];
        $recorded = $snapshot['input_fingerprint'] ?? null;
        $scope = array_key_exists('asset_scope', $snapshot) && is_array($snapshot['asset_scope']) ? $snapshot['asset_scope'] : null;
        $current = app(RunInputService::class)->capture($scope);
        $changes = [];
        if (is_array($recorded) && ($recorded['version'] ?? null) === 1) {
            foreach (['criteria' => 'Active criteria, their scoring guidance, or accepted weights changed.', 'comparisons' => 'Pairwise comparison values changed.', 'policy' => 'Institution settings or priority policy changed.', 'assets' => 'Assets, their eligibility/details, or latest completed assessment evidence changed.'] as $key => $message) {
                if (! is_string($recorded[$key] ?? null) || ! hash_equals($recorded[$key], $current[$key])) {
                    $changes[] = $message;
                }
            }
            $state = $changes ? 'changed' : 'current';
        } else {
            $state = 'unknown';
            if (isset($snapshot['thresholds']) && app(RunInputService::class)->digest($snapshot['thresholds']) !== app(RunInputService::class)->digest(app(SettingsService::class)->get()['thresholds'])) {
                // Legacy JSON may store integral thresholds as ints; compare numerical values below.
                if (collect(['critical', 'high', 'medium'])->contains(fn ($level) => (float) ($snapshot['thresholds'][$level] ?? -1) !== (float) (app(SettingsService::class)->get()['thresholds'][$level] ?? -1))) {
                    $changes[] = 'Priority thresholds changed since this legacy run.';
                }
            }
            $savedCriteria = collect($snapshot['criteria'] ?? []);
            $active = Criterion::active()->get();
            if ($savedCriteria->isNotEmpty() && ($savedCriteria->pluck('id')->sort()->values()->all() !== $active->pluck('id')->sort()->values()->all() || $savedCriteria->contains(fn ($c) => ! $active->contains(fn ($a) => $a->id === $c['id'] && $a->code === ($c['code'] ?? '') && $a->direction === ($c['direction'] ?? '') && $a->name === ($c['name'] ?? '') && $a->weight !== null && abs($a->weight - ($c['weight'] ?? -1)) < 1e-10)))) {
                $changes[] = 'Criteria or accepted weights differ from this legacy run.';
            }
            $latestAssets = NetworkAsset::whereIn('id', $results->pluck('network_asset_id'))->with('latestCompletedAssessment')->get()->keyBy('id');
            if ($results->contains(fn ($r) => ! isset($latestAssets[$r->network_asset_id]) || $latestAssets[$r->network_asset_id]->latestCompletedAssessment?->id !== $r->assessment_id)) {
                $changes[] = 'An included asset has a different latest completed assessment.';
            }
            if ($changes) {
                $state = 'changed';
            }
        }
        $coverage = $snapshot['coverage'] ?? $this->legacyCoverage($results, $skipped);
        $coverage['percentage'] = $coverage['eligible'] > 0 ? $coverage['ranked'] / $coverage['eligible'] * 100 : null;
        $cutoff = today()->subMonthsNoOverflow(app(SettingsService::class)->get()['reassessment_months']);
        $overdue = $results->filter(fn ($r) => isset($r->asset_snapshot['assessment_date']) && Carbon::parse($r->asset_snapshot['assessment_date'])->startOfDay()->lte($cutoff))->count();

        return ['state' => $run->status === 'completed' ? $state : 'draft', 'changes' => $changes, 'legacy' => ! is_array($recorded) || ($recorded['version'] ?? null) !== 1, 'coverage' => $coverage, 'current' => $current, 'skipped' => $skipped,
            'exclusion_counts' => collect($skipped)->countBy('reason')->all(), 'overdue_assessments' => $overdue, 'provenance' => app(DemoDataService::class)->run($run, $results),
            'rerun_scope' => $scope === null ? 'all assets' : 'the original selected asset IDs', 'is_scoped' => $scope !== null];
    }

    private function legacyCoverage(Collection $results, array $skipped): array
    {
        $inactive = collect($skipped)->where('reason', 'Asset is inactive or retired.')->count();

        return ['candidates' => $results->count() + count($skipped), 'eligible' => $results->count() + count($skipped) - $inactive, 'ranked' => $results->count(), 'skipped' => count($skipped), 'ineligible' => $inactive, 'missing_assessments' => collect($skipped)->where('reason', 'No completed assessment.')->count(), 'incomplete_assessments' => collect($skipped)->where('reason', 'Latest completed assessment is missing current active criteria.')->count()];
    }
}
