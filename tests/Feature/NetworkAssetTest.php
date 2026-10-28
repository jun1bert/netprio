<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\NetworkAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsNetpriorRecords;
use Tests\TestCase;

class NetworkAssetTest extends TestCase
{
    use BuildsNetpriorRecords, RefreshDatabase;

    public function test_inventory_crud_validation_and_filtering(): void
    {
        $this->actingAs($this->institutionUser());
        $values = $this->assetValues();
        $this->get(route('assets.create'))->assertOk();
        $this->post(route('assets.store'), $values + ['ip_address' => 'invalid'])->assertSessionHasErrors('ip_address');
        $this->post(route('assets.store'), $values)->assertSessionHasNoErrors();
        $asset = NetworkAsset::firstOrFail();
        $this->post(route('assets.store'), $values)->assertSessionHasErrors('asset_code');
        $this->get(route('assets.index', ['search' => 'Core', 'assessment_status' => 'unassessed']))->assertOk()->assertSee('SW-001');
        $this->get(route('assets.index', ['status' => 'retired']))->assertOk()->assertDontSee('SW-001');
        $this->get(route('assets.show', $asset))->assertOk();
        $this->get(route('assets.edit', $asset))->assertOk();
        $this->put(route('assets.update', $asset), array_replace($values, ['status' => 'retired']))->assertSessionHasNoErrors();
        $this->assertSame('retired', $asset->fresh()->status);
        $this->delete(route('assets.destroy', $asset))->assertRedirect(route('assets.index'));
        $this->assertDatabaseCount('network_assets', 0);
    }

    public function test_management_cannot_write_and_assets_with_history_cannot_be_deleted(): void
    {
        $admin = $this->institutionUser();
        $values = $this->assetValues();
        $asset = NetworkAsset::create($values);
        Assessment::create(['network_asset_id' => $asset->id, 'assessed_by' => $admin->id, 'assessment_date' => '2026-10-27']);
        $this->actingAs($admin)->delete(route('assets.destroy', $asset))->assertSessionHasErrors('asset');
        $this->assertNotNull($asset->fresh());
        $this->actingAs($this->institutionUser('management'))->get(route('assets.show', $asset))->assertOk();
        $this->put(route('assets.update', $asset), $values)->assertForbidden();
        $this->post(route('assets.store'), $values)->assertForbidden();
    }
}
