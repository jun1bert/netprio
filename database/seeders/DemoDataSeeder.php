<?php

namespace Database\Seeders;

use App\Models\AssetType;
use App\Models\Criterion;
use App\Models\Location;
use App\Models\NetworkAsset;
use App\Models\PairwiseComparison;
use App\Models\PrioritizationRun;
use App\Models\User;
use App\Services\AhpService;
use App\Services\AssessmentService;
use App\Services\AuditService;
use App\Services\PrioritizationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
    private const MARKER = '[NETPRIOR_DEMO_V1]';

    private const RUN = '[DEMO] Campus network upgrade review';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo data can only be inserted in local/testing environments.');
        }
        $actor = User::where('is_active', true)->whereHas('role', fn ($q) => $q->where('code', 'administrator'))->orderBy('id')->first();
        if (! $actor) {
            throw new RuntimeException('Assign an active administrator account before seeding the demo workflow. No account credentials will be created or changed.');
        }
        $previous = Auth::user();
        Auth::setUser($actor);
        try {
            DB::transaction(function () use ($actor) {
                if (! Criterion::exists()) {
                    $this->call(ReferenceDataSeeder::class);
                }
                $criteria = Criterion::orderBy('id')->lockForUpdate()->get()->where('is_active', true)->values();
                if ($criteria->isEmpty() || $criteria->count() > 10) {
                    throw new RuntimeException('Activate 1–10 criteria before creating demo assessments.');
                }
                $locations = [];
                foreach (['Data Center', 'Library', 'Administration Building', 'Engineering Laboratory', 'Lecture Block', 'ICT Office'] as $name) {
                    $locations[$name] = Location::firstOrCreate(['name' => 'DEMO - '.$name], ['building' => 'Demo campus', 'floor' => $name === 'Data Center' ? 'Ground floor' : 'First floor', 'description' => self::MARKER.' Synthetic test location, not an institutional record.', 'is_active' => true]);
                }
                // code, name, type, location, need score, final status, assessment workflow
                $assets = [
                    ['DEMO-SW-CORE-001', 'Core distribution switch', 'Managed Switch', 'Data Center', 4.8, 'active', 'completed'],
                    ['DEMO-FW-001', 'Perimeter firewall', 'Firewall', 'Data Center', 4.5, 'active', 'completed'],
                    ['DEMO-RTR-001', 'Campus WAN router', 'Router', 'ICT Office', 4.0, 'active', 'completed'],
                    ['DEMO-AP-LIB-001', 'Library access point', 'Access Point', 'Library', 3.7, 'active', 'completed'],
                    ['DEMO-SW-LAB-001', 'Engineering laboratory switch', 'Managed Switch', 'Engineering Laboratory', 3.1, 'under_maintenance', 'completed'],
                    ['DEMO-SRV-001', 'Administration application server', 'Server', 'Administration Building', 2.8, 'active', 'completed'],
                    ['DEMO-AP-LEC-001', 'Lecture block access point', 'Access Point', 'Lecture Block', 2.0, 'active', 'completed'],
                    ['DEMO-UPS-001', 'Data center UPS', 'UPS', 'Data Center', 1.4, 'active', 'completed'],
                    ['DEMO-AP-NEW-001', 'New library access point', 'Access Point', 'Library', 1.5, 'active', 'none'],
                    ['DEMO-SW-DRAFT-001', 'ICT office switch awaiting review', 'Managed Switch', 'ICT Office', 3.6, 'under_maintenance', 'draft'],
                    ['DEMO-RTR-OLD-001', 'Inactive backup router', 'Router', 'Administration Building', 3.0, 'inactive', 'completed'],
                    ['DEMO-SW-RET-001', 'Retired laboratory switch', 'Managed Switch', 'Engineering Laboratory', 4.6, 'retired', 'completed'],
                ];
                $ids = [];
                foreach ($assets as $index => [$code,$name,$type,$location,$need,$status,$workflow]) {
                    $assetType = AssetType::firstOrCreate(['name' => $type], ['is_active' => true]);
                    $asset = NetworkAsset::firstOrCreate(['asset_code' => $code], [
                        'name' => '[DEMO] '.$name, 'asset_type_id' => $assetType->id, 'location_id' => $locations[$location]->id,
                        'manufacturer' => 'Demo manufacturer', 'model' => 'Demo model '.($index + 1), 'serial_number' => 'DEMO-SN-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                        'ip_address' => '192.0.2.'.($index + 10), 'mac_address' => sprintf('02:00:00:00:00:%02x', $index + 1),
                        'installation_date' => today()->subYears($index < 3 ? 8 : 3)->toDateString(), 'warranty_expiry' => today()->subMonths(6)->toDateString(),
                        'acquisition_cost' => [145000, 98000, 65000, 12500, 45000, 120000, 8500, 28000, 15000, 22000, 18000, 30000][$index],
                        'status' => 'active', 'notes' => self::MARKER.' Synthetic demonstration asset. Scores and measurements are test data only.',
                    ]);
                    if (! str_contains($asset->notes ?? '', self::MARKER)) {
                        throw new RuntimeException('A non-demo asset already uses '.$code.'. No existing data was overwritten.');
                    }
                    $ids[] = $asset->id;
                    if (! $asset->is_demo) {
                        $asset->is_demo = true;
                        $asset->save();
                    }
                    if (! $asset->wasRecentlyCreated) {
                        continue;
                    }
                    app(AuditService::class)->record('demo.asset_created', $asset, ['source' => 'demo_seeder', 'synthetic' => true]);
                    if ($workflow !== 'none') {
                        if ($index === 0) {
                            $this->assess($actor, $asset, $criteria, 2.7, 'completed', today()->subMonths(18)->toDateString());
                        }
                        $this->assess($actor, $asset, $criteria, $need, $workflow, today()->subDays($index + 1)->toDateString());
                    }
                    // Complete any historical evaluation before retiring the asset.
                    $asset->update(['status' => $status]);
                }
                if (PrioritizationRun::where('name', self::RUN)->exists()) {
                    $this->command?->info('Demo records already exist; existing edits and decision history were preserved.');

                    return;
                }
                $activeIds = $criteria->pluck('id');
                $hasJudgments = PairwiseComparison::whereIn('criterion_a_id', $activeIds)->whereIn('criterion_b_id', $activeIds)->exists();
                if (! $hasJudgments) {
                    $pairs = [];
                    foreach ($criteria as $i => $a) {
                        foreach ($criteria as $j => $b) {
                            if ($i < $j) {
                                $pairs[$a->id.'_'.$b->id] = 1;
                            }
                        }
                    }
                    app(AhpService::class)->save($actor, $pairs);
                    $this->command?->info('Initialized equal-importance demonstration judgments because no active comparisons existed.');
                }
                try {
                    $run = app(PrioritizationService::class)->run($actor, self::RUN, $ids);
                    app(AuditService::class)->record('demo.dataset_seeded', $run, ['source' => 'demo_seeder', 'synthetic' => true, 'asset_codes' => array_column($assets, 0)]);
                    $this->command?->info('Demo dataset ready: 6 locations, 12 assets, 12 assessments, and a demo-only prioritization run. Existing comparisons were preserved.');
                } catch (ValidationException $e) {
                    // Preserve a user's incomplete/inconsistent judgments rather than replacing them with demo values.
                    $this->command?->warn('Demo inventory and assessments were added. No demo run was created: '.$e->getMessage());
                }
            });
        } finally {
            if ($previous) {
                Auth::setUser($previous);
            } else {
                Auth::forgetGuards();
            }
        }
    }

    private function assess(User $actor, NetworkAsset $asset, $criteria, float $need, string $status, string $date): void
    {
        $scores = [];
        foreach ($criteria as $index => $criterion) {
            if ($status === 'draft' && $index >= 3) {
                break;
            }
            $value = max(1, min(5, $need + ($index % 3 - 1) * 0.1));
            $scores[$criterion->id] = [
                'score' => round($criterion->direction === 'cost' ? 6 - $value : $value, 2),
                'raw_value' => match ($criterion->code) {
                    'UTILIZATION' => round(($value - 1) * 23, 1),'DOWNTIME' => round(($value - 1) * 4, 1),'COST' => 5000 + $asset->id * 100,default => null
                },
                'raw_unit' => match ($criterion->code) {
                    'UTILIZATION' => '%','DOWNTIME' => 'hours/month','COST' => 'demo currency',default => null
                },
                'remarks' => self::MARKER.' Synthetic example evidence for exploring the assessment workflow.',
            ];
        }
        app(AssessmentService::class)->save($actor, [
            'network_asset_id' => $asset->id, 'assessment_date' => $date, 'status' => $status,
            'remarks' => self::MARKER.' DEMONSTRATION ONLY. Not an actual technical evaluation or expert judgment.', 'scores' => $scores,
        ]);
    }
}
