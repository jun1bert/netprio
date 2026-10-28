<?php

namespace App\Http\Controllers;

use App\Models\Criterion;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    public function edit(SettingsService $service)
    {
        Gate::authorize('manage-settings');

        return view('admin.settings', ['settings' => $service->get()]);
    }

    public function update(Request $request)
    {
        Gate::authorize('manage-settings');
        $values = $request->validate(['institution_name' => ['required', 'string', 'max:255'], 'reassessment_months' => ['required', 'integer', 'between:1,120'],
            'critical' => ['required', 'numeric', 'max:100', 'gt:high'], 'high' => ['required', 'numeric', 'gt:medium'], 'medium' => ['required', 'numeric', 'min:0']]);
        DB::transaction(function () use ($values) {
            Criterion::orderBy('id')->lockForUpdate()->get();
            $setting = SystemSetting::updateOrCreate(['key' => 'policy'], ['value' => ['institution_name' => $values['institution_name'], 'reassessment_months' => (int) $values['reassessment_months'],
                'thresholds' => ['critical' => (float) $values['critical'], 'high' => (float) $values['high'], 'medium' => (float) $values['medium']]]]);
            app(AuditService::class)->record('settings.updated', $setting, ['policy' => $setting->value]);
        });

        return redirect()->route('settings.edit')->with('status', 'Settings saved. Historical runs retain their original policy.');
    }
}
