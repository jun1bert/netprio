<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssessmentRequest;
use App\Models\Assessment;
use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Services\AssessmentService;
use App\Services\ScoringRubricService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssessmentController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Assessment::class);
        $values = $request->validate(['status' => ['nullable', 'in:draft,completed'], 'search' => ['nullable', 'string', 'max:255']]);
        $query = Assessment::with(['networkAsset', 'evaluator']);
        if ($values['status'] ?? null) {
            $query->where('status', $values['status']);
        }
        if ($values['search'] ?? null) {
            $query->whereHas('networkAsset', fn ($q) => $q->where('name', 'like', '%'.$values['search'].'%')->orWhere('asset_code', 'like', '%'.$values['search'].'%'));
        }

        return view('assessments.index', ['assessments' => $query->orderByDesc('assessment_date')->orderByDesc('id')->paginate(15)->withQueryString()]);
    }

    private function form(Assessment $assessment)
    {
        $criteria = Criterion::where(fn ($q) => $q->where('is_active', true)->orWhereHas('assessmentScores', fn ($q) => $q->where('assessment_id', $assessment->id ?? 0)))->orderBy('display_order')->orderBy('id')->get();

        return view('assessments.form', ['assessment' => $assessment, 'criteria' => $criteria, 'rubrics' => $criteria->mapWithKeys(fn ($c) => [$c->id => app(ScoringRubricService::class)->for($c)]), 'scores' => $assessment->assessmentScores->keyBy('criterion_id'),
            'assetContext' => NetworkAsset::with(['location', 'assetType'])->find(old('network_asset_id', $assessment->network_asset_id)),
            'assets' => NetworkAsset::with('location')->where('status', '!=', 'retired')->orderBy('asset_code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->asset_code.' · '.$a->name.' · '.$a->location?->name])]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', Assessment::class);

        return $this->form(new Assessment(['assessment_date' => today(), 'status' => 'draft', 'network_asset_id' => $request->integer('asset')]));
    }

    public function edit(Assessment $assessment)
    {
        Gate::authorize('update', $assessment);

        return $this->form($assessment);
    }

    public function show(Assessment $assessment)
    {
        Gate::authorize('view', $assessment);

        return view('assessments.show', ['assessment' => $assessment->load(['networkAsset', 'evaluator', 'assessmentScores.criterion'])]);
    }

    public function store(AssessmentRequest $request, AssessmentService $service)
    {
        $assessment = $service->save($request->user(), $request->validated());

        return redirect()->route('assessments.show', $assessment)->with('status', 'Assessment saved.');
    }

    public function update(AssessmentRequest $request, Assessment $assessment, AssessmentService $service)
    {
        $service->save($request->user(), $request->validated(), $assessment);

        return redirect()->route('assessments.show', $assessment)->with('status', 'Assessment saved.');
    }

    public function destroy(Request $request, Assessment $assessment, AssessmentService $service)
    {
        $service->delete($request->user(), $assessment);

        return redirect()->route('assessments.index')->with('status', 'Draft deleted.');
    }
}
