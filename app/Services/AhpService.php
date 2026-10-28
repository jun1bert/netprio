<?php

namespace App\Services;

use App\Models\Criterion;
use App\Models\PairwiseComparison;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AhpService
{
    private const RI = [1 => 0.0, 2 => 0.0, 3 => 0.58, 4 => 0.90, 5 => 1.12, 6 => 1.24, 7 => 1.32, 8 => 1.41, 9 => 1.45, 10 => 1.49];

    public function calculateMatrix(array $matrix): array
    {
        $n = count($matrix);
        if ($n < 1 || $n > 10 || ! array_is_list($matrix)) {
            throw ValidationException::withMessages(['criteria' => 'AHP requires 1–10 active criteria; the configured Random Index table supports up to 10.']);
        }
        foreach ($matrix as $i => $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) !== $n) {
                throw ValidationException::withMessages(['comparisons' => 'The pairwise comparison matrix is incomplete.']);
            }
            foreach ($row as $j => $value) {
                if (! is_numeric($value) || ! is_finite((float) $value) || $value < 1 / 9 - 1e-12 || $value > 9 || ! isset($matrix[$j][$i]) || ! is_numeric($matrix[$j][$i]) || abs($value * $matrix[$j][$i] - 1) > 1e-8 || ($i === $j && abs($value - 1) > 1e-8)) {
                    throw ValidationException::withMessages(['comparisons' => 'The matrix must be positive, reciprocal, and have a diagonal of 1.']);
                }
            }
        }
        $totals = array_fill(0, $n, 0.0);
        foreach ($matrix as $row) {
            foreach ($row as $j => $value) {
                $totals[$j] += $value;
            }
        }
        $normalized = [];
        foreach ($matrix as $i => $row) {
            foreach ($row as $j => $value) {
                $normalized[$i][$j] = $value / $totals[$j];
            }
        }
        $weights = array_map(fn ($row) => array_sum($row) / $n, $normalized);
        // Power iteration refines the normalized-row-average estimate to the principal eigenvector.
        for ($iteration = 0; $iteration < 10000; $iteration++) {
            $next = $this->multiply($matrix, $weights);
            $sum = array_sum($next);
            $next = array_map(fn ($value) => $value / $sum, $next);
            $difference = max(array_map(fn ($a, $b) => abs($a - $b), $next, $weights));
            $weights = $next;
            if ($difference < 1e-13) {
                break;
            }
        }
        $weighted = $this->multiply($matrix, $weights);
        $lambda = array_sum(array_map(fn ($value, $weight) => $value / $weight, $weighted, $weights)) / $n;
        $ci = $n > 1 ? max(0.0, ($lambda - $n) / ($n - 1)) : 0.0;
        $cr = self::RI[$n] > 0 ? $ci / self::RI[$n] : 0.0;

        return ['matrix' => $matrix, 'column_totals' => $totals, 'normalized_matrix' => $normalized,
            'weights' => $weights, 'lambda_max' => $lambda, 'consistency_index' => $ci,
            'consistency_ratio' => $cr, 'random_index' => self::RI[$n], 'consistent' => $cr <= 0.10];
    }

    private function multiply(array $matrix, array $weights): array
    {
        return array_map(fn ($row) => array_sum(array_map(fn ($a, $b) => $a * $b, $row, $weights)), $matrix);
    }

    public function analyze(): array
    {
        $criteria = Criterion::active()->orderBy('display_order')->orderBy('id')->get();
        if ($criteria->isEmpty() || $criteria->count() > 10) {
            throw ValidationException::withMessages(['criteria' => 'AHP requires 1–10 active criteria; the configured Random Index table supports up to 10.']);
        }
        $comparisons = PairwiseComparison::whereIn('criterion_a_id', $criteria->pluck('id'))->whereIn('criterion_b_id', $criteria->pluck('id'))->get()->keyBy(fn ($pair) => $pair->criterion_a_id.'_'.$pair->criterion_b_id);
        $matrix = [];
        foreach ($criteria as $i => $a) {
            foreach ($criteria as $j => $b) {
                if ($a->id === $b->id) {
                    $matrix[$i][$j] = 1.0;

                    continue;
                }
                $pair = $comparisons->get(min($a->id, $b->id).'_'.max($a->id, $b->id));
                if (! $pair) {
                    throw ValidationException::withMessages(['comparisons' => 'Complete every active criterion pair before calculating weights.']);
                }
                $matrix[$i][$j] = $a->id < $b->id ? $pair->comparison_value : 1 / $pair->comparison_value;
            }
        }
        $result = $this->calculateMatrix($matrix);
        $result['criteria'] = $criteria->values()->map(fn ($c, $i) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'direction' => $c->direction, 'description' => $c->description, 'weight' => $result['weights'][$i]])->all();

        return $result;
    }

    public function save(User $actor, array $pairs): array
    {
        Gate::forUser($actor)->authorize('manage-comparisons');

        return DB::transaction(function () use ($actor, $pairs) {
            $criteria = Criterion::orderBy('id')->lockForUpdate()->get()->where('is_active', true)->values();
            if ($criteria->isEmpty() || $criteria->count() > 10) {
                throw ValidationException::withMessages(['criteria' => 'Activate between 1 and 10 criteria before using AHP.']);
            }
            $expected = [];
            foreach ($criteria as $i => $a) {
                foreach ($criteria as $j => $b) {
                    if ($i >= $j) {
                        continue;
                    }
                    $key = $a->id.'_'.$b->id;
                    $expected[] = $key;
                    $value = $pairs[$key] ?? null;
                    $valid = is_numeric($value) && collect(range(1, 9))->contains(fn ($scale) => abs((float) $value - $scale) < 1e-12 || abs((float) $value - 1 / $scale) < 1e-12);
                    if (! $valid) {
                        throw ValidationException::withMessages(['pairs.'.$key => 'Choose a Saaty importance value for every pair.']);
                    }
                }
            }
            if (array_diff(array_keys($pairs), $expected)) {
                throw ValidationException::withMessages(['pairs' => 'The criterion set changed. Reload the comparison page.']);
            }
            foreach ($expected as $key) {
                [$a, $b] = explode('_', $key);
                $value = (float) $pairs[$key];
                foreach (range(1, 9) as $scale) {
                    if (abs($value - $scale) < 1e-12) {
                        $value = (float) $scale;
                        break;
                    }
                    if (abs($value - 1 / $scale) < 1e-12) {
                        $value = 1 / $scale;
                        break;
                    }
                }
                // PDO string conversion must not truncate 1/9 below the database's lower bound.
                PairwiseComparison::updateOrCreate(['criterion_a_id' => $a, 'criterion_b_id' => $b], ['comparison_value' => sprintf('%.17g', $value), 'created_by' => $actor->id]);
            }
            Criterion::query()->update(['weight' => null]);
            $result = $this->analyze();
            if ($result['consistent']) {
                foreach ($result['criteria'] as $criterion) {
                    Criterion::whereKey($criterion['id'])->update(['weight' => sprintf('%.17g', $criterion['weight'])]);
                }
            }
            app(AuditService::class)->record('ahp.comparisons_updated', $criteria->first(), ['consistency_ratio' => $result['consistency_ratio'], 'accepted' => $result['consistent']]);

            return $result;
        });
    }
}
