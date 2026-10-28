<?php

namespace App\Http\Controllers;

use App\Models\Criterion;
use App\Models\PairwiseComparison;
use App\Services\AhpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AhpController extends Controller
{
    public function index(AhpService $service)
    {
        Gate::authorize('view-criteria');
        $calculation = null;
        $problem = null;
        try {
            $calculation = $service->analyze();
        } catch (ValidationException $e) {
            $problem = $e->getMessage();
        }
        $criteria = Criterion::active()->orderBy('id')->get();
        $pairs = [];
        $stored = PairwiseComparison::whereIn('criterion_a_id', $criteria->pluck('id'))->whereIn('criterion_b_id', $criteria->pluck('id'))->get()->keyBy(fn ($p) => $p->criterion_a_id.'_'.$p->criterion_b_id);
        foreach ($criteria as $i => $a) {
            foreach ($criteria as $j => $b) {
                if ($i < $j) {
                    $key = $a->id.'_'.$b->id;
                    $pairs[] = ['key' => $key, 'a' => $a->name, 'b' => $b->name, 'value' => $stored->get($key)?->comparison_value];
                }
            }
        }

        return view('ahp.index', compact('criteria', 'pairs', 'calculation', 'problem'));
    }

    public function store(Request $request, AhpService $service)
    {
        Gate::authorize('manage-comparisons');
        $values = $request->validate(['pairs' => ['sometimes', 'array'], 'pairs.*' => ['required', 'numeric']]);
        $result = $service->save($request->user(), $values['pairs'] ?? []);

        return redirect()->route('ahp.index')->with('status', $result['consistent'] ? 'Judgments accepted. Calculated weights are consistent.' : 'Judgments saved for review. CR exceeds 0.10; weights were not accepted. Revise your comparisons.');
    }
}
