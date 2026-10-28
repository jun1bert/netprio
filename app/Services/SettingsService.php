<?php

namespace App\Services;

use App\Models\SystemSetting;

class SettingsService
{
    public function get(): array
    {
        return array_replace_recursive([
            'institution_name' => config('netprior.institution_name'),
            'reassessment_months' => config('netprior.reassessment_months'),
            'thresholds' => config('netprior.thresholds'),
        ], SystemSetting::find('policy')?->value ?? []);
    }
}
