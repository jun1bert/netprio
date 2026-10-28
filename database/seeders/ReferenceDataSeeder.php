<?php

namespace Database\Seeders;

use App\Models\AssetType;
use App\Models\Criterion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            Criterion::orderBy('id')->lockForUpdate()->get();
            $changed = false;
            foreach (['Router', 'Managed Switch', 'Access Point', 'Firewall', 'Server', 'Fiber Link', 'ONU', 'UPS', 'Network Controller'] as $name) {
                AssetType::firstOrCreate(['name' => $name], ['is_active' => true]);
            }
            foreach ([
                ['PERFORMANCE', 'Performance', 'benefit', 'Rate performance shortfall: 1 = meets requirements; 5 = severe performance deficiency.'],
                ['CONDITION', 'Physical / technical condition', 'benefit', 'Rate deterioration: 1 = very good condition; 5 = critical deterioration or faults.'],
                ['DOWNTIME', 'Downtime / failure frequency', 'benefit', 'Rate interruption severity: 1 = very infrequent; 5 = frequent or severe failures. Add measured hours/month when available.'],
                ['UTILIZATION', 'Utilization', 'benefit', 'Rate capacity pressure: 1 = ample capacity; 5 = sustained congestion or overload. Add measured utilization percent.'],
                ['CRITICALITY', 'Operational criticality', 'benefit', 'Rate institutional dependence: 1 = low impact; 5 = essential service with severe disruption impact.'],
                ['SECURITY', 'Security risk', 'benefit', 'Rate exposure: 1 = low risk and supported controls; 5 = severe exposure or unsupported vulnerable infrastructure.'],
                ['COST', 'Cost / financial impact', 'cost', 'Rate upgrade financial burden: 1 = low burden / strongest upgrade preference; 5 = high burden / weakest preference. This cost direction favors affordable upgrades.'],
            ] as $index => [$code,$name,$direction,$description]) {
                $criterion = Criterion::firstOrCreate(['code' => $code], compact('name', 'direction', 'description') + ['display_order' => $index, 'is_active' => true]);
                $changed = $changed || $criterion->wasRecentlyCreated;
            }
            if ($changed) {
                Criterion::query()->update(['weight' => null]);
            }
        });
    }
}
