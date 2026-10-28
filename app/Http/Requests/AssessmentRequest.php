<?php

namespace App\Http\Requests;

use App\Models\Assessment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('assessment') ? $this->user()->can('update', $this->route('assessment')) : $this->user()->can('create', Assessment::class);
    }

    public function rules(): array
    {
        return [
            'network_asset_id' => [$this->route('assessment') ? 'sometimes' : 'required', 'integer', Rule::exists('network_assets', 'id')->where(fn ($q) => $q->where('status', '!=', 'retired'))],
            'assessment_date' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::in(['draft', 'completed'])], 'remarks' => ['nullable', 'string', 'max:20000'],
            'scores' => ['nullable', 'array'], 'scores.*' => ['array:score,raw_value,raw_unit,remarks'],
            'scores.*.score' => ['nullable', 'numeric', 'between:1,5'],
            'scores.*.raw_value' => ['nullable', 'numeric', 'between:-1000000000000,1000000000000'],
            'scores.*.raw_unit' => ['nullable', 'string', 'max:100'],
            'scores.*.remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
