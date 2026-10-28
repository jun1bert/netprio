<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssessmentService
{
    public function save(User $actor, array $values, ?Assessment $assessment = null): Assessment
    {
        return DB::transaction(function () use ($actor, $values, $assessment) {
            $criteria = Criterion::orderBy('id')->lockForUpdate()->get();
            $record = $assessment ? Assessment::lockForUpdate()->findOrFail($assessment->id) : new Assessment;
            Gate::forUser($actor)->authorize($record->exists ? 'update' : 'create', $record->exists ? $record : Assessment::class);
            $assetId = $record->exists ? $record->network_asset_id : $values['network_asset_id'];
            $asset = NetworkAsset::findOrFail($assetId);
            if ($asset->status === 'retired') {
                throw ValidationException::withMessages(['network_asset_id' => 'Retired assets cannot be assessed.']);
            }
            if ($record->exists && isset($values['network_asset_id']) && (int) $values['network_asset_id'] !== $record->network_asset_id) {
                throw ValidationException::withMessages(['network_asset_id' => 'An assessment cannot be transferred to another asset.']);
            }
            $existing = $record->exists ? $record->assessmentScores()->pluck('criterion_id')->all() : [];
            $allowed = $criteria->where('is_active', true)->pluck('id')->merge($existing)->map(fn ($id) => (string) $id)->all();
            $scores = $values['scores'] ?? [];
            foreach ($scores as $id => $entry) {
                if (! in_array((string) $id, $allowed, true)) {
                    throw ValidationException::withMessages(['scores' => 'One or more criteria are no longer available. Reload the assessment.']);
                }
                if (($entry['score'] ?? null) === null && (($entry['raw_value'] ?? null) !== null || ($entry['remarks'] ?? null) !== null)) {
                    throw ValidationException::withMessages(['scores.'.$id.'.score' => 'Enter a 1–5 score alongside raw measurements or remarks.']);
                }
            }
            $active = $criteria->where('is_active', true);
            if ($values['status'] === 'completed') {
                if ($active->isEmpty()) {
                    throw ValidationException::withMessages(['scores' => 'Activate at least one criterion before completing an assessment.']);
                }
                foreach ($active as $criterion) {
                    if (($scores[$criterion->id]['score'] ?? null) === null) {
                        throw ValidationException::withMessages(['scores.'.$criterion->id.'.score' => 'A score is required for '.$criterion->name.' before completion.']);
                    }
                }
            }
            $record->fill(['network_asset_id' => $assetId, 'assessed_by' => $record->exists ? $record->assessed_by : $actor->id,
                'assessment_date' => $values['assessment_date'], 'status' => $values['status'], 'remarks' => $values['remarks'] ?? null])->save();
            foreach ($scores as $id => $entry) {
                if (($entry['score'] ?? null) === null) {
                    $record->assessmentScores()->where('criterion_id', $id)->delete();

                    continue;
                }
                $criterion = $criteria->firstWhere('id', (int) $id);
                $rubric = app(ScoringRubricService::class)->for($criterion);
                $record->assessmentScores()->updateOrCreate(['criterion_id' => $id], [
                    'score' => $entry['score'], 'raw_value' => $entry['raw_value'] ?? null, 'raw_unit' => $entry['raw_unit'] ?? null,
                    'remarks' => $entry['remarks'] ?? null,
                    'criterion_snapshot' => ['id' => $criterion->id, 'code' => $criterion->code, 'name' => $criterion->name, 'direction' => $criterion->direction, 'description' => $criterion->description, 'rubric' => $rubric['levels'], 'rubric_source' => $rubric['source']],
                ]);
            }
            app(AuditService::class)->record($record->status === 'completed' ? 'assessment.completed' : 'assessment.saved', $record);

            return $record;
        });
    }

    public function delete(User $actor, Assessment $assessment): void
    {
        DB::transaction(function () use ($actor, $assessment) {
            $record = Assessment::lockForUpdate()->findOrFail($assessment->id);
            Gate::forUser($actor)->authorize('delete', $record);
            $record->assessmentScores()->delete();
            $record->delete();
            app(AuditService::class)->record('assessment.deleted', $record);
        });
    }
}
