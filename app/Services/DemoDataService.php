<?php

namespace App\Services;

use App\Models\NetworkAsset;
use App\Models\PrioritizationRun;
use Illuminate\Support\Collection;

class DemoDataService
{
    public function workspace(): array
    {
        if (! collect(['view-dashboard', 'view-assets', 'view-rankings', 'view-reports'])->contains(fn ($ability) => auth()->user()?->can($ability))) {
            return ['demo' => 0, 'total' => 0];
        }
        $demo = NetworkAsset::where('is_demo', true)->count();

        return ['demo' => $demo, 'total' => $demo ? NetworkAsset::count() : 0];
    }

    public function snapshotIsDemo(array $snapshot): bool
    {
        if (array_key_exists('is_demo', $snapshot)) {
            return (bool) $snapshot['is_demo'];
        }

        return str_starts_with($snapshot['asset_code'] ?? '', 'DEMO-') || str_contains($snapshot['name'] ?? '', '[DEMO]');
    }

    public function summary(int $demo, int $total, bool $legacy = false): array
    {
        return ['demo' => $demo, 'total' => $total, 'other' => $total - $demo, 'kind' => $demo > 0 ? ($demo === $total ? 'demo' : 'mixed') : ($legacy ? 'legacy' : 'institutional'), 'legacy' => $legacy];
    }

    public function run(PrioritizationRun $run, ?Collection $results = null): array
    {
        $recorded = $run->decision_snapshot['data_provenance'] ?? null;
        if (is_array($recorded)) {
            return $recorded;
        }
        $results ??= $run->prioritizationResults()->get();
        $demo = $results->filter(fn ($r) => $this->snapshotIsDemo($r->asset_snapshot ?? []))->count();

        return $this->summary($demo, $results->count(), true);
    }
}
