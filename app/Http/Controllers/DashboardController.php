<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AuditLog;
use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Models\PrioritizationRun;
use App\Services\RunStatusService;
use App\Services\SettingsService;
use App\Services\WorkflowReadinessService;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __invoke()
    {
        Gate::authorize('view-dashboard');
        $latest = PrioritizationRun::completed()->orderByDesc('completed_at')->orderByDesc('id')->first();
        $priorities = $latest ? $latest->prioritizationResults()->selectRaw('priority_level, COUNT(*) as total')->groupBy('priority_level')->pluck('total', 'priority_level') : collect();
        $cutoff = today()->subMonthsNoOverflow(app(SettingsService::class)->get()['reassessment_months']);
        $due = NetworkAsset::whereIn('status', ['active', 'under_maintenance'])->where(fn ($q) => $q->whereDoesntHave('latestCompletedAssessment')->orWhereHas('latestCompletedAssessment', fn ($q) => $q->where('assessment_date', '<=', $cutoff)))->count();

        return view('dashboard', [
            'total' => NetworkAsset::count(), 'active' => NetworkAsset::where('status', 'active')->count(), 'due' => $due,
            'average' => Gate::allows('view-assessments') ? AssessmentScore::whereHas('assessment', fn ($q) => $q->completed())->avg('score') : null,
            'latest' => $latest, 'priorities' => $priorities,
            'top' => $latest?->prioritizationResults()->orderBy('rank')->orderBy('id')->take(5)->get() ?? collect(),
            'recent' => Gate::allows('view-assessments') ? Assessment::with(['networkAsset', 'evaluator'])->orderByDesc('created_at')->orderByDesc('id')->take(5)->get() : collect(),
            'activity' => Gate::allows('view-audit-logs') ? AuditLog::with('user')->latest('id')->take(5)->get() : collect(),
            'recentRuns' => PrioritizationRun::completed()->orderByDesc('completed_at')->orderByDesc('id')->take(4)->get(),
            'byType' => NetworkAsset::join('asset_types', 'asset_types.id', '=', 'network_assets.asset_type_id')->selectRaw('asset_types.name as label, COUNT(*) as total')->groupBy('asset_types.id', 'asset_types.name')->orderByDesc('total')->get(),
            'byLocation' => NetworkAsset::join('locations', 'locations.id', '=', 'network_assets.location_id')->selectRaw('locations.name as label, COUNT(*) as total')->groupBy('locations.id', 'locations.name')->orderByDesc('total')->take(10)->get(),
            'weights' => Criterion::active()->whereNotNull('weight')->orderByDesc('weight')->get(),
            'readiness' => app(WorkflowReadinessService::class)->for(auth()->user()),
            'runStatus' => $latest ? app(RunStatusService::class)->inspect($latest) : null,
        ]);
    }
}
