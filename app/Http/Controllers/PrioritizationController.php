<?php

namespace App\Http\Controllers;

use App\Models\PrioritizationResult;
use App\Models\PrioritizationRun;
use App\Services\PrioritizationService;
use App\Services\RunComparisonService;
use App\Services\RunStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PrioritizationController extends Controller
{
    public function compare(Request $request, PrioritizationRun $run)
    {
        Gate::authorize('view', $run);
        abort_unless($run->status === 'completed', 404);
        $values = $request->validate(['against' => ['nullable', 'integer', 'exists:prioritization_runs,id', Rule::notIn([$run->id])]]);
        $baseline = isset($values['against']) ? PrioritizationRun::completed()->findOrFail($values['against']) : PrioritizationRun::completed()->where('id', '<', $run->id)->orderByDesc('id')->first();
        if ($baseline) {
            Gate::authorize('view', $baseline);
        }
        $comparison = $baseline ? app(RunComparisonService::class)->compare($baseline, $run) : null;
        $choices = PrioritizationRun::completed()->whereKeyNot($run->id)->orderByDesc('id')->get()->mapWithKeys(fn ($item) => [$item->id => $item->name.' — '.$item->completed_at?->format('M d, Y H:i')])->all();

        return view('runs.compare', compact('run', 'baseline', 'comparison', 'choices'));
    }

    public function index(Request $request)
    {
        Gate::authorize('viewAny', PrioritizationRun::class);
        $values = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $query = PrioritizationRun::with('creator')->withCount(['prioritizationResults', 'prioritizationResults as demo_results_count' => function ($query) {
            $query->where('asset_snapshot->is_demo', true)->orWhere(function ($legacy) {
                $legacy->whereNull('asset_snapshot->is_demo')->where(function ($markers) {
                    $markers->where('asset_snapshot->asset_code', 'like', 'DEMO-%')->orWhere('asset_snapshot->name', 'like', '%[DEMO]%');
                });
            });
        }]);
        if (! $request->user()->can('run-prioritization')) {
            $query->completed();
        }
        if ($values['search'] ?? null) {
            $query->where('name', 'like', '%'.$values['search'].'%');
        }

        return view('runs.index', ['runs' => $query->orderByDesc('id')->paginate(15)->withQueryString()]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', PrioritizationRun::class);

        $values = $request->validate(['rerun_of' => ['nullable', 'integer', 'exists:prioritization_runs,id']]);
        $source = isset($values['rerun_of']) ? PrioritizationRun::completed()->findOrFail($values['rerun_of']) : null;
        if ($source) {
            Gate::authorize('view', $source);
        }

        return view('runs.create', compact('source'));
    }

    public function store(Request $request, PrioritizationService $service)
    {
        Gate::authorize('create', PrioritizationRun::class);
        $values = $request->validate(['name' => ['required', 'string', 'max:255'], 'rerun_of' => ['nullable', 'integer', 'exists:prioritization_runs,id']]);
        $source = isset($values['rerun_of']) ? PrioritizationRun::completed()->findOrFail($values['rerun_of']) : null;
        if ($source) {
            Gate::authorize('view', $source);
        }
        $scope = is_array($source?->decision_snapshot['asset_scope'] ?? null) ? $source->decision_snapshot['asset_scope'] : null;
        $run = $service->run($request->user(), $values['name'], $scope, $source?->id);

        return redirect()->route('runs.show', $run)->with('status', 'Prioritization completed. Historical results have been saved.');
    }

    public function show(Request $request, PrioritizationRun $run)
    {
        Gate::authorize('view', $run);
        $values = $request->validate([
            'priority' => ['nullable', 'in:critical,high,medium,low'],
            'location' => ['nullable', 'string', 'max:255'], 'asset_type' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:rank,score,asset,location'], 'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $query = $run->prioritizationResults()->when($values['priority'] ?? null, fn ($q, $level) => $q->where('priority_level', $level));
        if ($values['location'] ?? null) {
            $query->where('asset_snapshot->location', $values['location']);
        }
        if ($values['asset_type'] ?? null) {
            $query->where('asset_snapshot->type', $values['asset_type']);
        }
        $sort = $values['sort'] ?? 'rank';
        $column = ['rank' => 'rank', 'score' => 'final_score', 'asset' => 'asset_snapshot->name', 'location' => 'asset_snapshot->location'][$sort];
        $results = $query->orderBy($column, $values['direction'] ?? ($sort === 'score' ? 'desc' : 'asc'))->orderBy('id')->paginate(20)->withQueryString();
        $snapshots = $run->prioritizationResults()->get(['asset_snapshot'])->pluck('asset_snapshot');
        $locations = $snapshots->pluck('location')->filter()->unique()->sort()->mapWithKeys(fn ($name) => [$name => $name])->all();
        $types = $snapshots->pluck('type')->filter()->unique()->sort()->mapWithKeys(fn ($name) => [$name => $name])->all();

        $runStatus = app(RunStatusService::class)->inspect($run);

        return view('runs.show', compact('run', 'results', 'locations', 'types', 'runStatus'));
    }

    public function result(PrioritizationRun $run, PrioritizationResult $result)
    {
        abort_unless($result->prioritization_run_id === $run->id, 404);
        Gate::authorize('view', $result);

        $runStatus = app(RunStatusService::class)->inspect($run);

        return view('runs.result', compact('run', 'result', 'runStatus'));
    }
}
