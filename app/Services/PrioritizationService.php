<?php

namespace App\Services;

use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Models\PrioritizationRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PrioritizationService
{
    public function normalize(float $score, string $direction): float
    {
        if (! is_finite($score) || $score < 1 || $score > 5 || ! in_array($direction, ['benefit', 'cost'], true)) {
            throw ValidationException::withMessages(['scores' => 'Scores must be between 1 and 5 with a valid criterion direction.']);
        }

        return ($direction === 'benefit' ? $score - 1 : 5 - $score) / 4;
    }

    public function classify(float $score, array $thresholds): string
    {
        foreach (['critical', 'high', 'medium'] as $level) {
            if ($score >= $thresholds[$level]) {
                return $level;
            }
        }

        return 'low';
    }

    public function run(User $actor, string $name, ?array $assetIds = null, ?int $sourceRunId = null): PrioritizationRun
    {
        Gate::forUser($actor)->authorize('run-prioritization');

        return DB::transaction(function () use ($actor, $name, $assetIds, $sourceRunId) {
            $criteria = Criterion::orderBy('id')->lockForUpdate()->get()->where('is_active', true)->keyBy('id');
            $ahp = app(AhpService::class)->analyze();
            if (! $ahp['consistent']) {
                throw ValidationException::withMessages(['ahp' => 'Prioritization blocked: AHP Consistency Ratio exceeds 0.10. Review comparisons.']);
            }
            foreach ($ahp['criteria'] as $c) {
                if ($criteria[$c['id']]->weight === null || abs($criteria[$c['id']]->weight - $c['weight']) > 1e-10) {
                    throw ValidationException::withMessages(['ahp' => 'Save and accept the current AHP comparisons before running prioritization.']);
                }
            }
            $policy = app(SettingsService::class)->get();
            $thresholds = $policy['thresholds'];
            $evaluated = [];
            $skipped = [];
            $assets = NetworkAsset::with(['assetType', 'location', 'latestCompletedAssessment.assessmentScores'])
                ->when($assetIds !== null, fn ($query) => $query->whereIn('id', $assetIds))
                ->orderBy('id')->lockForUpdate()->get();
            $inputFingerprint = app(RunInputService::class)->capture($assetIds, $assets, $criteria->values(), $policy);
            foreach ($assets as $asset) {
                $reason = null;
                $assessment = $asset->latestCompletedAssessment;
                $scores = $assessment?->assessmentScores->keyBy('criterion_id');
                if (! in_array($asset->status, ['active', 'under_maintenance'], true)) {
                    $reason = 'Asset is inactive or retired.';
                } elseif (! $assessment) {
                    $reason = 'No completed assessment.';
                } elseif ($criteria->keys()->diff($scores->keys())->isNotEmpty()) {
                    $reason = 'Latest completed assessment is missing current active criteria.';
                }
                if ($reason) {
                    $skipped[] = ['id' => $asset->id, 'asset_code' => $asset->asset_code, 'name' => $asset->name, 'reason' => $reason, 'is_demo' => (bool) $asset->is_demo];

                    continue;
                }
                $breakdown = [];
                $total = 0.0;
                foreach ($ahp['criteria'] as $criterion) {
                    $score = $scores[$criterion['id']];
                    $normalized = $this->normalize($score->score, $criterion['direction']);
                    $contribution = $normalized * $criterion['weight'] * 100;
                    $total += $contribution;
                    $breakdown[] = $criterion + ['score' => $score->score, 'normalized_score' => $normalized, 'contribution' => $contribution,
                        'raw_value' => $score->raw_value, 'raw_unit' => $score->raw_unit, 'remarks' => $score->remarks];
                }
                // Bound only floating-point endpoint error, without rounding the score.
                $total = max(0.0, min(100.0, $total));
                $level = $this->classify($total, $thresholds);
                $drivers = collect($breakdown)->where('contribution', '>', 0)->sortByDesc('contribution')->take(3)->pluck('name')->implode(', ');
                $recommendation = match ($level) {
                    'critical' => 'Immediate technical review and upgrade planning are recommended.',
                    'high' => 'Schedule an upgrade evaluation in the next planning cycle.',
                    'medium' => 'Plan targeted improvements and monitor the asset.',
                    default => 'Continue routine monitoring and reassess when conditions change.',
                };
                if ($drivers !== '') {
                    $recommendation .= ' Largest weighted contributors: '.$drivers.'.';
                }
                $recommendation .= ' Final upgrade decisions remain with authorized institutional personnel.';
                $evaluated[] = ['network_asset_id' => $asset->id, 'assessment_id' => $assessment->id,
                    'final_score' => $total, 'priority_level' => $level, 'recommendation' => $recommendation,
                    'asset_snapshot' => ['asset_code' => $asset->asset_code, 'name' => $asset->name, 'type' => $asset->assetType->name,
                        'location' => $asset->location->name, 'building' => $asset->location->building, 'floor' => $asset->location->floor,
                        'status' => $asset->status, 'assessment_date' => $assessment->assessment_date->toDateString(), 'assessed_by' => $assessment->assessed_by, 'is_demo' => (bool) $asset->is_demo],
                    'score_breakdown' => $breakdown];
            }
            if ($evaluated === []) {
                throw ValidationException::withMessages(['assets' => 'No eligible assets. Complete an assessment with every active criterion for an active or maintenance asset.']);
            }
            usort($evaluated, fn ($a, $b) => ($b['final_score'] <=> $a['final_score']) ?: strcmp($a['asset_snapshot']['asset_code'], $b['asset_snapshot']['asset_code']) ?: ($a['network_asset_id'] <=> $b['network_asset_id']));
            $demoCount = collect($evaluated)->filter(fn ($r) => $r['asset_snapshot']['is_demo'])->count();
            $coverage = ['candidates' => $assets->count(), 'eligible' => $inputFingerprint['eligible_count'], 'ranked' => count($evaluated), 'skipped' => count($skipped),
                'ineligible' => $assets->count() - $inputFingerprint['eligible_count'], 'missing_assessments' => collect($skipped)->where('reason', 'No completed assessment.')->count(),
                'incomplete_assessments' => collect($skipped)->where('reason', 'Latest completed assessment is missing current active criteria.')->count()];
            $run = PrioritizationRun::create(['name' => $name, 'created_by' => $actor->id, 'status' => 'draft',
                'consistency_index' => sprintf('%.17g', $ahp['consistency_index']), 'consistency_ratio' => sprintf('%.17g', $ahp['consistency_ratio']),
                'decision_snapshot' => $ahp + ['algorithm' => 'directional-linear-1-5-v1', 'thresholds' => $thresholds, 'institution_name' => $policy['institution_name'], 'asset_scope' => $assetIds,
                    'input_fingerprint' => $inputFingerprint, 'coverage' => $coverage, 'data_provenance' => app(DemoDataService::class)->summary($demoCount, count($evaluated)), 'rerun_of' => $sourceRunId,
                    'normalization' => 'benefit: (score-1)/4; cost: (5-score)/4; contributions scaled to 0–100',
                    'tie_policy' => 'Equal full-precision scores share a competition rank; asset code orders tied rows.',
                    'skipped_assets' => $skipped, 'eligible_statuses' => ['active', 'under_maintenance']]]);
            $previous = null;
            $rank = 0;
            foreach ($evaluated as $index => $result) {
                if ($previous === null || $result['final_score'] !== $previous) {
                    $rank = $index + 1;
                }
                $previous = $result['final_score'];
                $result['final_score'] = sprintf('%.17g', $result['final_score']);
                $run->prioritizationResults()->create($result + ['rank' => $rank]);
            }
            $run->update(['status' => 'completed', 'completed_at' => now()]);
            app(AuditService::class)->record('prioritization.executed', $run, ['evaluated' => count($evaluated), 'skipped' => count($skipped)]);

            return $run;
        });
    }
}
