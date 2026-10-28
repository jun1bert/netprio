<?php

namespace App\Services;

use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Models\PairwiseComparison;
use Illuminate\Support\Collection;

class RunInputService
{
    public function capture(?array $scope = null, ?Collection $assets = null, ?Collection $criteria = null, ?array $policy = null): array
    {
        $criteria ??= Criterion::active()->orderBy('id')->get();
        $criteria = $criteria->sortBy('id')->values();
        $policy ??= app(SettingsService::class)->get();
        $assets ??= NetworkAsset::with(['assetType', 'location', 'latestCompletedAssessment.assessmentScores'])->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))->orderBy('id')->get();
        $assets = $assets->sortBy('id')->values();
        $ids = $criteria->pluck('id');
        $criterionValues = $criteria->map(fn ($c) => $c->only(['id', 'code', 'name', 'description', 'direction', 'weight', 'display_order', 'rubric']))->all();
        $pairs = PairwiseComparison::whereIn('criterion_a_id', $ids)->whereIn('criterion_b_id', $ids)->orderBy('criterion_a_id')->orderBy('criterion_b_id')->get()->map(fn ($p) => $p->only(['criterion_a_id', 'criterion_b_id', 'comparison_value']))->all();
        $eligible = 0;
        $scorable = 0;
        $assetValues = $assets->map(function ($asset) use ($ids, &$eligible, &$scorable) {
            $assessment = $asset->latestCompletedAssessment;
            $scores = $assessment?->assessmentScores->whereIn('criterion_id', $ids)->sortBy('criterion_id')->values();
            if (in_array($asset->status, ['active', 'under_maintenance'], true)) {
                $eligible++;
                if ($ids->isNotEmpty() && $assessment && $ids->diff($scores->pluck('criterion_id'))->isEmpty()) {
                    $scorable++;
                }
            }

            return [
                'id' => $asset->id, 'asset_code' => $asset->asset_code, 'name' => $asset->name, 'status' => $asset->status, 'is_demo' => (bool) $asset->is_demo,
                'location' => [$asset->location_id, $asset->location?->name, $asset->location?->building, $asset->location?->floor],
                'type' => [$asset->asset_type_id, $asset->assetType?->name],
                'assessment' => $assessment ? ['id' => $assessment->id, 'date' => $assessment->assessment_date->toDateString(), 'assessed_by' => $assessment->assessed_by, 'remarks' => $assessment->remarks,
                    'scores' => $scores->map(fn ($s) => $s->only(['criterion_id', 'score', 'raw_value', 'raw_unit', 'remarks', 'criterion_snapshot']))->all()] : null,
            ];
        })->all();

        return ['version' => 1, 'criteria' => $this->digest($criterionValues), 'comparisons' => $this->digest($pairs), 'policy' => $this->digest($policy), 'assets' => $this->digest($assetValues),
            'candidate_count' => $assets->count(), 'eligible_count' => $eligible, 'scorable_count' => $scorable, 'asset_ids' => $assets->pluck('id')->all()];
    }

    public function digest(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonical($value), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($entry) => $this->canonical($entry), $value);
    }
}
