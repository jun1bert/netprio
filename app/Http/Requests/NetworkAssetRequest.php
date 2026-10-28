<?php

namespace App\Http\Requests;

use App\Models\NetworkAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NetworkAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('asset') ? $this->user()->can('update', $this->route('asset')) : $this->user()->can('create', NetworkAsset::class);
    }

    public function rules(): array
    {
        $asset = $this->route('asset');
        $rules = [
            'asset_type_id' => ['required', Rule::exists('asset_types', 'id')->where(fn ($q) => $q->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $asset?->asset_type_id ?? 0)))],
            'location_id' => ['required', Rule::exists('locations', 'id')->where(fn ($q) => $q->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $asset?->location_id ?? 0)))],
            'asset_code' => ['required', 'string', 'max:100', Rule::unique('network_assets')->ignore($asset)],
            'name' => ['required', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'], 'mac_address' => ['nullable', 'mac_address'],
            'installation_date' => ['nullable', 'date', 'before_or_equal:today'],
            'warranty_expiry' => ['nullable', 'date'],
            'acquisition_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'status' => ['required', Rule::in(['active', 'inactive', 'under_maintenance', 'retired'])],
            'notes' => ['nullable', 'string', 'max:20000'],
        ];
        foreach (['manufacturer', 'model', 'serial_number'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }
}
