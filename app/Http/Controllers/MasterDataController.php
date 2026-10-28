<?php

namespace App\Http\Controllers;

use App\Http\Requests\MasterDataRequest;
use App\Models\Criterion;
use App\Services\AuditService;
use App\Services\MasterData;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MasterDataController extends Controller
{
    private function context(Request $request): array
    {
        return ['kind' => $request->route('kind')] + MasterData::definition($request->route('kind'));
    }

    public function index(Request $request)
    {
        $data = $this->context($request);
        Gate::authorize('viewAny', $data['model']);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'active' => ['nullable', 'in:0,1']]);
        $query = $data['model']::query();
        if ($filters['search'] ?? null) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }
        if (isset($filters['active'])) {
            $query->where('is_active', $filters['active']);
        }

        return view('master-data.index', $data + ['records' => $query->orderBy('name')->paginate(15)->withQueryString()]);
    }

    public function create(Request $request)
    {
        $data = $this->context($request);
        Gate::authorize('create', $data['model']);

        return view('master-data.form', $data + ['record' => new $data['model'](['is_active' => true, 'display_order' => 0])]);
    }

    public function edit(Request $request)
    {
        $data = $this->context($request);
        $record = $data['model']::findOrFail($request->route('record'));
        Gate::authorize('update', $record);

        return view('master-data.form', $data + compact('record'));
    }

    public function store(MasterDataRequest $request, AuditService $audit)
    {
        return $this->save($request, $audit);
    }

    public function update(MasterDataRequest $request, AuditService $audit)
    {
        return $this->save($request, $audit);
    }

    private function save(MasterDataRequest $request, AuditService $audit)
    {
        $data = $this->context($request);
        DB::transaction(function () use ($request, $audit, $data) {
            // All decision configuration changes serialize on the criterion set.
            if ($data['kind'] === 'criteria') {
                Criterion::orderBy('id')->lockForUpdate()->get();
            }
            $record = $request->route('record') ? $data['model']::lockForUpdate()->findOrFail($request->route('record')) : new $data['model'];
            $values = $request->validated();
            if (array_key_exists('rubric', $values)) {
                $values['rubric'] = collect($values['rubric'] ?? [])->filter(fn ($v) => is_string($v) && trim($v) !== '')->isEmpty() ? null : array_map('trim', $values['rubric']);
            }
            $record->fill($values);
            if ($record instanceof Criterion && $record->exists && $record->isDirty('direction') && $record->assessmentScores()->exists()) {
                throw ValidationException::withMessages(['direction' => 'This criterion has assessment history. Create a new criterion and deactivate the original to change its scoring direction.']);
            }
            $record->save();
            if ($record instanceof Criterion) {
                Criterion::query()->update(['weight' => null]);
            }
            $audit->record($data['kind'].'.saved', $record);
        });

        return redirect()->route($data['kind'].'.index')->with('status', 'Record saved.');
    }

    public function destroy(Request $request, AuditService $audit)
    {
        $data = $this->context($request);
        $record = $data['model']::findOrFail($request->route('record'));
        Gate::authorize('delete', $record);
        try {
            DB::transaction(function () use ($record, $audit, $data) {
                if ($record instanceof Criterion) {
                    Criterion::orderBy('id')->lockForUpdate()->get();
                }
                $record->delete();
                if ($record instanceof Criterion) {
                    Criterion::query()->update(['weight' => null]);
                }
                $audit->record($data['kind'].'.deleted', $record);
            });
        } catch (QueryException $exception) {
            if (! in_array($exception->getCode(), ['23000', '23503'])) {
                throw $exception;
            }

            return back()->withErrors(['record' => 'This record is in use. Deactivate it to preserve history.']);
        }

        return redirect()->route($data['kind'].'.index')->with('status', 'Record deleted.');
    }
}
