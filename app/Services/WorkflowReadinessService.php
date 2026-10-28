<?php

namespace App\Services;

use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Models\PrioritizationRun;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class WorkflowReadinessService
{
    public function for(User $user): array
    {
        $criteria = Criterion::active()->orderBy('id')->get();
        $assets = NetworkAsset::whereIn('status', ['active', 'under_maintenance'])->with('latestCompletedAssessment.assessmentScores')->get(['id']);
        $ids = $criteria->pluck('id');
        $ready = $criteria->isNotEmpty() ? $assets->filter(fn ($asset) => $asset->latestCompletedAssessment && $ids->diff($asset->latestCompletedAssessment->assessmentScores->pluck('criterion_id'))->isEmpty())->count() : 0;
        $missing = $assets->count() - $ready;
        $ahpReady = false;
        $cr = null;
        try {
            $analysis = app(AhpService::class)->analyze();
            $cr = $analysis['consistency_ratio'];
            $byId = $criteria->keyBy('id');
            $accepted = count($analysis['criteria']) === $criteria->count() && collect($analysis['criteria'])->every(fn ($c) => isset($byId[$c['id']]) && $byId[$c['id']]->weight !== null && abs($byId[$c['id']]->weight - $c['weight']) <= 1e-10);
            $ahpReady = $analysis['consistent'] && $accepted;
            $ahpDetail = ! $analysis['consistent'] ? 'CR '.number_format($cr, 4).' exceeds 0.10. Review the comparisons.' : (! $accepted ? 'Comparisons are consistent, but current weights still need to be accepted.' : 'Current weights are accepted; CR '.number_format($cr, 4).'.');
        } catch (ValidationException $e) {
            $ahpDetail = $e->getMessage();
        }
        $canRun = $ready > 0 && $ahpReady;
        $latest = PrioritizationRun::completed()->orderByDesc('completed_at')->orderByDesc('id')->first();
        $assetLink = ['label' => $user->can('manage-assets') && $assets->isEmpty() ? 'Register assets' : 'View assets', 'url' => route($user->can('manage-assets') && $assets->isEmpty() ? 'assets.create' : 'assets.index')];
        if ($criteria->isEmpty()) {
            $assessmentLink = ['label' => 'Review criteria', 'url' => route('criteria.index')];
        } else {
            $assessmentLink = ['label' => $user->can('create-assessments') ? 'Assess missing assets' : 'View assessment coverage', 'url' => route('assets.index', ['assessment_status' => 'needs_scoring'])];
        }
        $ahpLink = ['label' => $user->can('manage-comparisons') ? 'Review comparisons' : 'View criteria weights', 'url' => route($user->can('manage-comparisons') ? 'ahp.index' : 'ahp.weights')];
        $runLink = $user->can('run-prioritization') && $canRun ? ['label' => 'Run prioritization', 'url' => route('runs.create')] : ['label' => 'Review rankings', 'url' => $latest ? route('runs.show', $latest) : route('runs.index')];
        // Custom roles may have dashboard access without access to every workflow module.
        if (! $user->can('view-assets') && ! ($user->can('manage-assets') && $assets->isEmpty())) {
            $assetLink['url'] = null;
        }
        if ($criteria->isEmpty() && ! $user->can('view-criteria')) {
            $assessmentLink['url'] = null;
        } elseif (! $criteria->isEmpty() && ! $user->can('view-assets')) {
            $assessmentLink = $user->can('create-assessments') ? ['label' => 'New assessment', 'url' => route('assessments.create')] : ['label' => 'Assessment access required', 'url' => null];
        }
        if (! $user->can('view-criteria')) {
            $ahpLink['url'] = null;
        }
        if (! ($user->can('run-prioritization') && $canRun) && ! $user->can('view-rankings')) {
            $runLink['url'] = null;
        }
        $steps = [
            ['key' => 'assets', 'title' => 'Register assets', 'state' => $assets->isNotEmpty() ? 'complete' : 'blocked', 'detail' => $assets->count().' assets are active or under maintenance. Inactive and retired assets are excluded.', 'action' => $assetLink],
            ['key' => 'assessments', 'title' => 'Complete assessments', 'state' => $assets->isNotEmpty() && $missing === 0 && $criteria->isNotEmpty() ? 'complete' : ($ready > 0 ? 'attention' : 'blocked'), 'detail' => $criteria->isEmpty() ? 'Activate decision criteria before completing assessments.' : $ready.' of '.$assets->count().' eligible assets have a completed assessment covering every active criterion.', 'action' => $assessmentLink],
            ['key' => 'ahp', 'title' => 'Validate AHP weights', 'state' => $ahpReady ? 'complete' : 'blocked', 'detail' => $ahpDetail, 'action' => $ahpLink],
            ['key' => 'run', 'title' => 'Run and review priorities', 'state' => $latest ? 'complete' : ($canRun ? 'ready' : 'blocked'), 'detail' => $latest ? 'Latest preserved run: '.$latest->name.'. It may not include subsequent changes.' : ($canRun ? 'The current inputs can produce a prioritization run.' : 'At least one fully scored asset and accepted, consistent weights are required.'), 'action' => $runLink],
        ];
        $next = match (true) {
            $assets->isEmpty() => $assetLink,$criteria->isEmpty() => $assessmentLink,$ready === 0 => $assessmentLink,! $ahpReady => $ahpLink,$missing > 0 => $assessmentLink,default => $runLink
        };

        return ['steps' => $steps, 'completed' => collect($steps)->where('state', 'complete')->count(), 'eligible' => $assets->count(), 'assessed' => $ready, 'missing' => $missing, 'can_run' => $canRun, 'consistency_ratio' => $cr, 'next' => $next, 'has_run' => (bool) $latest,
            'summary' => $canRun ? 'Ready to rank '.$ready.' scored '.($ready === 1 ? 'asset' : 'assets') : 'Complete the requirements to run prioritization',
            'note' => $canRun && $missing > 0 ? $missing.' eligible '.($missing === 1 ? 'asset still needs' : 'assets still need').' scores and will be skipped. You can proceed with partial coverage.' : ($canRun ? 'Assessment coverage is complete for the current active criteria.' : 'Review the checklist below. Some steps may require an administrator or evaluator.')];
    }
}
