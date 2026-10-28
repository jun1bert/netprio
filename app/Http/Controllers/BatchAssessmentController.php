<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssetType;
use App\Models\Criterion;
use App\Models\Location;
use App\Models\NetworkAsset;
use App\Services\AssessmentService;
use App\Services\RunInputService;
use App\Services\ScoringRubricService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BatchAssessmentController extends Controller
{
    private function criteriaSignature($criteria): string
    {
        return app(RunInputService::class)->digest($criteria->map(fn ($c) => $c->only(['id', 'code', 'name', 'direction', 'description', 'rubric']))->values()->all());
    }

    private function draftSignature(Assessment $draft): string
    {
        return app(RunInputService::class)->digest([$draft->only(['id', 'network_asset_id', 'assessed_by', 'assessment_date', 'status', 'remarks', 'updated_at']), $draft->assessmentScores->sortBy('criterion_id')->map(fn ($score) => $score->only(['criterion_id', 'score', 'raw_value', 'raw_unit', 'remarks', 'criterion_snapshot']))->values()->all()]);
    }

    public function index(Request $request)
    {
        Gate::authorize('create', Assessment::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'location_id' => ['nullable', 'integer'], 'asset_type_id' => ['nullable', 'integer']]);
        $criteria = Criterion::active()->orderBy('id')->get();
        $assets = NetworkAsset::with(['location', 'assetType', 'assessments' => fn ($q) => $q->where('status', 'draft')->with('assessmentScores')->orderByDesc('id')])->where('status', '!=', 'retired')
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('asset_code', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%')))
            ->when($filters['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['asset_type_id'] ?? null, fn ($q, $id) => $q->where('asset_type_id', $id))
            ->orderBy('asset_code')->orderBy('id')->paginate(20)->withQueryString();
        $token = (string) Str::uuid();
        $drafts = $assets->getCollection()->mapWithKeys(fn ($asset) => [$asset->id => $asset->assessments->first(fn ($draft) => $request->user()->can('update', $draft))]);
        $tokens = collect($request->session()->get('batch_assessments', []))->filter(fn ($entry) => ($entry['issued_at'] ?? 0) > now()->subHours(2)->timestamp)->take(-9)->all();
        $tokens[$token] = ['actor' => $request->user()->id, 'assets' => $assets->pluck('id')->all(), 'criteria' => $this->criteriaSignature($criteria), 'drafts' => $drafts->map(fn ($draft) => $draft ? ['id' => $draft->id, 'version' => $this->draftSignature($draft)] : null)->all(), 'issued_at' => now()->timestamp];
        $request->session()->put('batch_assessments', $tokens);

        return view('assessments.batch', compact('criteria', 'assets', 'token', 'drafts') + [
            'types' => AssetType::orderBy('name')->pluck('name', 'id')->all(), 'locations' => Location::orderBy('name')->pluck('name', 'id')->all(),
            'rubrics' => $criteria->mapWithKeys(fn ($c) => [$c->id => app(ScoringRubricService::class)->for($c)]),
        ]);
    }

    public function store(Request $request, AssessmentService $service)
    {
        Gate::authorize('create', Assessment::class);
        // Unchecked rows are intentionally ignored, including their unused inputs.
        $rows = collect(is_array($request->input('rows')) ? $request->input('rows') : [])->filter(fn ($row) => is_array($row) && in_array($row['selected'] ?? null, [1, '1', true], true))->all();
        $request->merge(['rows' => $rows]);
        $values = $request->validate([
            'batch_token' => ['required', 'uuid'],
            'action' => ['required', 'in:draft,completed'], 'rows' => ['required', 'array', 'min:1', 'max:20'],
            'rows.*' => ['array:selected,scores,remarks,assessment_date'], 'rows.*.selected' => ['required', 'accepted'],
            'rows.*.assessment_date' => ['required', 'date', 'before_or_equal:today'],
            'rows.*.scores' => ['nullable', 'array'], 'rows.*.scores.*' => ['nullable', 'numeric', 'between:1,5'],
            'rows.*.remarks' => ['nullable', 'string', 'max:20000'],
        ]);
        $token = $request->session()->get('batch_assessments.'.$values['batch_token']);
        if (! $token || ($token['actor'] ?? null) !== $request->user()->id || ($token['issued_at'] ?? 0) < now()->subHours(2)->timestamp) {
            throw ValidationException::withMessages(['batch_token' => 'This batch has expired or was already saved. Reload the workspace before saving again.']);
        }
        foreach (array_keys($rows) as $id) {
            if (! ctype_digit((string) $id) || ! in_array((int) $id, $token['assets'], true)) {
                throw ValidationException::withMessages(['rows' => 'Select only assets on this batch page. Reload before selecting a different group.']);
            }
        }
        $saved = DB::transaction(function () use ($values, $rows, $token, $service, $request) {
            $criteria = Criterion::orderBy('id')->lockForUpdate()->get()->where('is_active', true)->values();
            if ($criteria->count() < 1 || $criteria->count() > 10 || $this->criteriaSignature($criteria) !== $token['criteria']) {
                throw ValidationException::withMessages(['rows' => 'Active criteria or scoring guidance changed. Reload the workspace and review the current guidance.']);
            }
            $assets = NetworkAsset::whereIn('id', array_keys($rows))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $errors = [];
            foreach ($rows as $id => $row) {
                if (! isset($assets[$id]) || $assets[$id]->status === 'retired') {
                    $errors['rows.'.$id.'.selected'] = 'This asset was removed or retired. Reload the workspace.';
                }
                if ($values['action'] === 'completed') {
                    foreach ($criteria as $criterion) {
                        if (($row['scores'][$criterion->id] ?? null) === null) {
                            $errors['rows.'.$id.'.scores.'.$criterion->id] = 'Enter a score for '.$assets->get($id)?->asset_code.' / '.$criterion->name.' before completion.';
                        }
                    }
                }
                if (collect(array_keys($row['scores'] ?? []))->diff($criteria->pluck('id'))->isNotEmpty()) {
                    $errors['rows.'.$id.'.scores'] = 'A submitted criterion is not active. Reload the workspace.';
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            $saved = [];
            foreach ($rows as $id => $row) {
                $original = $token['drafts'][$id] ?? null;
                $draft = $original ? Assessment::lockForUpdate()->find($original['id']) : null;
                if ($original && (! $draft || $draft->network_asset_id !== (int) $id || $draft->status !== 'draft' || $this->draftSignature($draft) !== $original['version'])) {
                    throw ValidationException::withMessages(['rows.'.$id.'.selected' => 'This draft changed or was completed in another tab. Reload before saving.']);
                }
                if ($draft) {
                    Gate::authorize('update', $draft);
                } elseif ($assets[$id]->assessments()->where('status', 'draft')->get()->contains(fn ($candidate) => $request->user()->can('update', $candidate))) {
                    throw ValidationException::withMessages(['rows.'.$id.'.selected' => 'An editable draft was created after this page opened. Reload to continue that draft.']);
                }
                $existing = $draft?->assessmentScores->keyBy('criterion_id');
                foreach ($row['scores'] ?? [] as $criterionId => $score) {
                    $evidence = $existing?->get($criterionId);
                    if ($score === null && $evidence && ($evidence->raw_value !== null || $evidence->raw_unit || $evidence->remarks)) {
                        throw ValidationException::withMessages(['rows.'.$id.'.scores.'.$criterionId => 'This score has supporting evidence. Use the detailed draft form to remove or revise it.']);
                    }
                }
                $assessment = $service->save($request->user(), ['network_asset_id' => $id, 'assessment_date' => $row['assessment_date'], 'status' => $values['action'], 'remarks' => $row['remarks'] ?? null,
                    'scores' => collect($row['scores'] ?? [])->map(fn ($score, $criterionId) => ['score' => $score] + ($existing?->get($criterionId)?->only(['raw_value', 'raw_unit', 'remarks']) ?? []))->all()], $draft);
                $saved[] = ['id' => $assessment->id, 'asset' => $assets[$id]->asset_code, 'status' => $assessment->status];
            }

            return $saved;
        });
        $request->session()->forget('batch_assessments.'.$values['batch_token']);

        return redirect()->route('assessments.batch', $request->only(['search', 'location_id', 'asset_type_id', 'page']))->with('batch_saved', $saved)->with('status', count($saved).' separate assessments saved. Unselected assets were unchanged.');
    }
}
