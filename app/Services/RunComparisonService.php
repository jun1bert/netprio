<?php

namespace App\Services;

use App\Models\PrioritizationRun;

class RunComparisonService
{
    public function compare(PrioritizationRun $before, PrioritizationRun $after): array
    {
        $old = $before->prioritizationResults()->get()->keyBy('network_asset_id');
        $new = $after->prioritizationResults()->get()->keyBy('network_asset_id');
        $left = $before->decision_snapshot ?? [];
        $right = $after->decision_snapshot ?? [];
        $warnings = [];
        $criteria = fn ($snapshot) => collect($snapshot['criteria'] ?? [])->map(fn ($c) => collect($c)->only(['id', 'direction', 'weight'])->all())->sortBy('id')->values()->all();
        $digest = app(RunInputService::class);
        if ($digest->digest($criteria($left)) !== $digest->digest($criteria($right))) {
            $warnings[] = 'Criteria, directions, or weights differ. Score changes cannot be attributed to asset condition alone.';
        }
        if ($digest->digest($left['thresholds'] ?? null) !== $digest->digest($right['thresholds'] ?? null)) {
            $warnings[] = 'Priority thresholds differ. A priority change may reflect policy rather than a score change.';
        }
        $scope = fn ($s) => is_array($s['asset_scope'] ?? null) ? collect($s['asset_scope'])->sort()->values()->all() : null;
        if ($scope($left) !== $scope($right) || $old->keys()->sort()->values()->all() !== $new->keys()->sort()->values()->all()) {
            $warnings[] = 'Selected scope or ranked asset membership differs. Rank changes also depend on which assets participated.';
        }
        if (! isset($left['algorithm'],$right['algorithm']) || $left['algorithm'] !== $right['algorithm']) {
            $warnings[] = 'Calculation versions are different or unavailable. Treat numerical comparisons with caution.';
        }
        $rows = $old->keys()->merge($new->keys())->unique()->map(function ($id) use ($old, $new) {
            $a = $old->get($id);
            $b = $new->get($id);

            return ['asset' => $b?->asset_snapshot ?? $a->asset_snapshot, 'before' => $a, 'after' => $b, 'rank_change' => $a && $b ? $a->rank - $b->rank : null, 'score_change' => $a && $b ? $b->final_score - $a->final_score : null];
        })->sortBy(fn ($r) => $r['after']?->rank ?? PHP_INT_MAX)->values();

        return compact('rows', 'warnings');
    }
}
