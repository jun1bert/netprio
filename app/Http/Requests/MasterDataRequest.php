<?php

namespace App\Http\Requests;

use App\Services\MasterData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MasterDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = MasterData::definition($this->route('kind'))['model'];
        $record = $this->route('record');

        return $record ? $this->user()->can('update', $model::findOrFail($record)) : $this->user()->can('create', $model);
    }

    public function rules(): array
    {
        $kind = $this->route('kind');
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['required', 'boolean'],
        ];
        if ($kind === 'locations') {
            $rules += ['building' => ['nullable', 'string', 'max:255'], 'floor' => ['nullable', 'string', 'max:50']];
        } elseif ($kind === 'asset-types') {
            $rules['name'][] = Rule::unique('asset_types')->ignore($this->route('record'));
        } else {
            $rules += [
                'code' => ['required', 'alpha_dash', 'max:50', Rule::unique('criteria')->ignore($this->route('record'))],
                'direction' => ['required', Rule::in(['benefit', 'cost'])],
                'display_order' => ['required', 'integer', 'min:0', 'max:10000'],
                'rubric' => ['nullable', 'array:1,2,3,4,5'],
                'rubric.*' => ['nullable', 'string', 'max:1000'],
            ];
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->route('kind') !== 'criteria' || ! is_array($this->input('rubric'))) {
                return;
            }
            $levels = collect($this->input('rubric'))->filter(fn ($value) => is_string($value) && trim($value) !== '');
            if ($levels->isNotEmpty() && ($levels->count() !== 5 || collect(range(1, 5))->diff($levels->keys())->isNotEmpty())) {
                $validator->errors()->add('rubric', 'Provide descriptions for all five scores, or leave all five blank to use the suggested/general scale.');
            }
        });
    }
}
