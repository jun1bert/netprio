<?php

namespace App\Http\Controllers;

use App\Http\Requests\NetworkAssetRequest;
use App\Models\AssetType;
use App\Models\Criterion;
use App\Models\Location;
use App\Models\NetworkAsset;
use App\Models\PrioritizationRun;
use App\Services\AuditService;
use App\Services\SettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class NetworkAssetController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', NetworkAsset::class);
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:255'], 'asset_type_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:active,inactive,under_maintenance,retired'],
            'priority' => ['nullable', 'in:critical,high,medium,low'], 'assessment_status' => ['nullable', 'in:completed,unassessed,due,needs_scoring,draft,overdue,not_started'],
            'sort' => ['nullable', 'in:name,asset_code,installation_date'], 'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $latestRun = PrioritizationRun::completed()->orderByDesc('completed_at')->orderByDesc('id')->first();
        $query = NetworkAsset::with(['assetType', 'location', 'latestCompletedAssessment', 'assessments' => fn ($q) => $q->where('status', 'draft')->orderByDesc('id'), 'prioritizationResults' => fn ($q) => $q->where('prioritization_run_id', $latestRun?->id ?? 0)]);
        if ($f['search'] ?? null) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$f['search'].'%')->orWhere('asset_code', 'like', '%'.$f['search'].'%')->orWhere('serial_number', 'like', '%'.$f['search'].'%'));
        }
        foreach (['asset_type_id', 'location_id', 'status'] as $key) {
            if ($f[$key] ?? null) {
                $query->where($key, $f[$key]);
            }
        }
        if ($f['priority'] ?? null) {
            $query->whereHas('prioritizationResults', fn ($q) => $q->where('prioritization_run_id', $latestRun?->id ?? 0)->where('priority_level', $f['priority']));
        }
        if (($f['assessment_status'] ?? '') === 'completed') {
            $query->whereHas('latestCompletedAssessment');
        }
        if (($f['assessment_status'] ?? '') === 'unassessed') {
            $query->whereDoesntHave('latestCompletedAssessment');
        }
        if (($f['assessment_status'] ?? '') === 'not_started') {
            $query->whereIn('status', ['active', 'under_maintenance'])->whereDoesntHave('latestCompletedAssessment')->whereDoesntHave('assessments', fn ($q) => $q->where('status', 'draft'));
        }
        if (($f['assessment_status'] ?? '') === 'draft') {
            $query->where('status', '!=', 'retired')->whereHas('assessments', fn ($q) => $q->where('status', 'draft'));
        }
        if (($f['assessment_status'] ?? '') === 'overdue') {
            $query->whereIn('status', ['active', 'under_maintenance'])->whereHas('latestCompletedAssessment', fn ($q) => $q->where('assessment_date', '<=', today()->subMonthsNoOverflow(app(SettingsService::class)->get()['reassessment_months'])));
        }
        if (($f['assessment_status'] ?? '') === 'needs_scoring') {
            $ids = Criterion::active()->pluck('id');
            $query->whereIn('status', ['active', 'under_maintenance']);
            if ($ids->isNotEmpty()) {
                $query->whereDoesntHave('latestCompletedAssessment', fn ($q) => $q->whereHas('assessmentScores', fn ($q) => $q->whereIn('criterion_id', $ids), '>=', $ids->count()));
            }
        }
        if (($f['assessment_status'] ?? '') === 'due') {
            $cutoff = today()->subMonthsNoOverflow(app(SettingsService::class)->get()['reassessment_months']);
            $query->whereIn('status', ['active', 'under_maintenance'])->where(fn ($q) => $q->whereDoesntHave('latestCompletedAssessment')->orWhereHas('latestCompletedAssessment', fn ($q) => $q->where('assessment_date', '<=', $cutoff)));
        }

        return view('assets.index', ['reassessmentCutoff' => today()->subMonthsNoOverflow(app(SettingsService::class)->get()['reassessment_months']), 'assets' => $query->orderBy($f['sort'] ?? 'asset_code', $f['direction'] ?? 'asc')->orderBy('id')->paginate(15)->withQueryString(), 'types' => AssetType::orderBy('name')->pluck('name', 'id'), 'locations' => Location::orderBy('name')->pluck('name', 'id')]);
    }

    private function form(NetworkAsset $asset)
    {
        return view('assets.form', compact('asset') + [
            'types' => AssetType::where(fn ($q) => $q->where('is_active', true)->orWhere('id', $asset->asset_type_id))->orderBy('name')->pluck('name', 'id'),
            'locations' => Location::where(fn ($q) => $q->where('is_active', true)->orWhere('id', $asset->location_id))->orderBy('name')->get()->mapWithKeys(fn ($l) => [$l->id => trim($l->name.' / '.$l->building.' '.$l->floor)]),
        ]);
    }

    public function create()
    {
        Gate::authorize('create', NetworkAsset::class);

        return $this->form(new NetworkAsset(['status' => 'active']));
    }

    public function edit(NetworkAsset $asset)
    {
        Gate::authorize('update', $asset);

        return $this->form($asset);
    }

    public function show(NetworkAsset $asset)
    {
        Gate::authorize('view', $asset);
        $asset->load(['assetType', 'location', 'latestCompletedAssessment']);
        if (Gate::allows('view-assessments')) {
            $asset->load('latestCompletedAssessment.assessmentScores.criterion');
        }
        $history = Gate::allows('view-assessments') ? $asset->assessments()->with('evaluator')->orderByDesc('assessment_date')->orderByDesc('id')->paginate(10, ['*'], 'assessments_page') : null;
        $rankings = Gate::allows('view-rankings') ? $asset->prioritizationResults()->whereHas('prioritizationRun', fn ($q) => $q->completed())->with('prioritizationRun')->orderByDesc('id')->paginate(10, ['*'], 'rankings_page') : null;

        return view('assets.show', compact('asset', 'history', 'rankings'));
    }

    public function store(NetworkAssetRequest $request, AuditService $audit)
    {
        $asset = DB::transaction(function () use ($request, $audit) {
            $asset = NetworkAsset::create($request->validated());
            $audit->record('asset.created', $asset);

            return $asset;
        });

        return redirect()->route('assets.show', $asset)->with('status', 'Network asset registered.');
    }

    public function update(NetworkAssetRequest $request, NetworkAsset $asset, AuditService $audit)
    {
        DB::transaction(function () use ($request, $asset, $audit) {
            $asset->update($request->validated());
            $audit->record('asset.updated', $asset);
        });

        return redirect()->route('assets.show', $asset)->with('status', 'Network asset updated.');
    }

    public function destroy(NetworkAsset $asset, AuditService $audit)
    {
        Gate::authorize('delete', $asset);
        try {
            DB::transaction(function () use ($asset, $audit) {
                $asset->delete();
                $audit->record('asset.deleted', $asset);
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return back()->withErrors(['asset' => 'This asset has history. Retire it instead of deleting it.']);
        }

        return redirect()->route('assets.index')->with('status', 'Asset deleted.');
    }
}
