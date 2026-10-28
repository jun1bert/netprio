<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\NetworkAsset;
use App\Models\PrioritizationRun;
use App\Services\AhpService;
use App\Services\DemoDataService;
use App\Services\RunStatusService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public const TYPES = [
        'inventory' => 'Asset inventory report', 'assessments' => 'Infrastructure assessment report',
        'rankings' => 'Upgrade priority ranking report', 'critical' => 'Critical infrastructure report',
        'weights' => 'Criteria weight report', 'consistency' => 'AHP consistency report', 'history' => 'Prioritization history report',
    ];

    public function index()
    {
        Gate::authorize('view-reports');

        return view('reports.index', ['types' => self::TYPES]);
    }

    public function show(Request $request, string $type, AhpService $service)
    {
        Gate::authorize('view-reports');
        abort_unless(isset(self::TYPES[$type]), 404);
        if ($type === 'assessments') {
            Gate::authorize('view-technical-reports');
        }
        $filters = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', $request->filled('from') ? 'after_or_equal:from' : 'date'],
            'run_id' => ['nullable', 'integer', 'exists:prioritization_runs,id'], 'search' => ['nullable', 'string', 'max:255'],
        ]);
        $runsQuery = PrioritizationRun::completed();
        $this->dates($runsQuery, 'completed_at', $filters);
        $runs = (clone $runsQuery)->orderByDesc('completed_at')->orderByDesc('id')->get(['id', 'name']);
        $run = isset($filters['run_id']) ? (clone $runsQuery)->findOrFail($filters['run_id']) : (clone $runsQuery)->orderByDesc('completed_at')->orderByDesc('id')->first();
        $rows = null;
        $calculation = null;
        $problem = null;
        $provenance = null;
        $runStatus = null;
        if ($type === 'inventory') {
            $query = NetworkAsset::with(['assetType', 'location']);
            $this->dates($query, 'created_at', $filters);
            if ($filters['search'] ?? null) {
                $query->where(fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')->orWhere('asset_code', 'like', '%'.$filters['search'].'%'));
            }
            $provenance = app(DemoDataService::class)->summary((clone $query)->where('is_demo', true)->count(), (clone $query)->count());
            $rows = $query->orderBy('asset_code')->paginate(50)->withQueryString();
        } elseif ($type === 'assessments') {
            $query = Assessment::completed()->with(['networkAsset', 'evaluator', 'assessmentScores.criterion']);
            $this->dates($query, 'assessment_date', $filters);
            if ($filters['search'] ?? null) {
                $query->whereHas('networkAsset', fn ($q) => $q->where('asset_code', 'like', '%'.$filters['search'].'%')->orWhere('name', 'like', '%'.$filters['search'].'%'));
            }
            $provenance = app(DemoDataService::class)->summary((clone $query)->whereHas('networkAsset', fn ($q) => $q->where('is_demo', true))->count(), (clone $query)->count());
            $rows = $query->orderByDesc('assessment_date')->orderByDesc('id')->paginate(25)->withQueryString();
        } elseif (in_array($type, ['rankings', 'critical'])) {
            if ($run) {
                $query = $run->prioritizationResults();
                if ($type === 'critical') {
                    $query->where('priority_level', 'critical');
                }
                if ($filters['search'] ?? null) {
                    $query->where(fn ($q) => $q->where('asset_snapshot->asset_code', 'like', '%'.$filters['search'].'%')->orWhere('asset_snapshot->name', 'like', '%'.$filters['search'].'%'));
                }
                $rows = $query->orderBy('rank')->orderBy('id')->paginate(50)->withQueryString();
            }
        } elseif ($type === 'history') {
            $rows = $runsQuery->with('creator')->withCount('prioritizationResults')->orderByDesc('completed_at')->orderByDesc('id')->paginate(50)->withQueryString();
        } else {
            if ($run) {
                $calculation = $run->decision_snapshot;
            } else {
                try {
                    $calculation = $service->analyze();
                } catch (ValidationException $e) {
                    $problem = $e->getMessage();
                }
            }
        }

        $institution = in_array($type, ['rankings', 'critical', 'weights', 'consistency'])
            ? ($run?->decision_snapshot['institution_name'] ?? app(SettingsService::class)->get()['institution_name'])
            : app(SettingsService::class)->get()['institution_name'];

        if ($run && in_array($type, ['rankings', 'critical', 'weights', 'consistency'])) {
            $runStatus = app(RunStatusService::class)->inspect($run);
            $provenance = $runStatus['provenance'];
        }

        return view('reports.show', ['title' => self::TYPES[$type], 'institution' => $institution, 'generatedAt' => now(), 'generatedBy' => $request->user()->name] + compact('type', 'rows', 'run', 'runs', 'calculation', 'problem', 'provenance', 'runStatus'));
    }

    private function dates($query, string $field, array $filters): void
    {
        if ($filters['from'] ?? null) {
            $query->whereDate($field, '>=', $filters['from']);
        }
        if ($filters['to'] ?? null) {
            $query->whereDate($field, '<=', $filters['to']);
        }
    }
}
