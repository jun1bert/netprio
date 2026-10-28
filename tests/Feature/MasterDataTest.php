<?php

namespace Tests\Feature;

use App\Models\AssetType;
use App\Models\Criterion;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_manage_master_data_and_weights_are_not_user_supplied(): void
    {
        $role = Role::create(['code' => 'administrator', 'name' => 'Administrator']);
        $user = User::factory()->create();
        $user->role()->associate($role)->save();
        $this->actingAs($user);
        foreach (['locations', 'asset-types', 'criteria'] as $kind) {
            $this->get(route($kind.'.index'))->assertOk();
            $this->get(route($kind.'.create'))->assertOk();
            $values = ['name' => 'Test '.$kind, 'is_active' => 1, 'code' => 'TEST', 'direction' => 'benefit', 'display_order' => 0, 'weight' => 1];
            $this->post(route($kind.'.store'), $values)->assertSessionHasNoErrors()->assertRedirect(route($kind.'.index'));
            $class = match ($kind) {
                'locations' => Location::class, 'asset-types' => AssetType::class, default => Criterion::class
            };
            $record = $class::firstOrFail();
            $this->get(route($kind.'.edit', $record->id))->assertOk();
            $this->put(route($kind.'.update', $record->id), array_replace($values, ['is_active' => 0]))->assertSessionHasNoErrors();
            $this->assertFalse($record->fresh()->is_active);
            if ($kind === 'criteria') {
                $this->assertNull($record->fresh()->weight);
            }
            $this->delete(route($kind.'.destroy', $record->id))->assertRedirect(route($kind.'.index'));
            $this->assertNull($record->fresh());
        }
        $this->assertDatabaseCount('audit_logs', 9);
    }

    public function test_management_can_read_but_cannot_modify_master_data(): void
    {
        $user = User::factory()->create();
        $user->role()->associate(Role::create(['code' => 'management', 'name' => 'Management']))->save();
        $this->actingAs($user);
        foreach (['locations', 'asset-types', 'criteria'] as $kind) {
            $this->get(route($kind.'.index'))->assertOk();
            $this->get(route($kind.'.create'))->assertForbidden();
            $this->post(route($kind.'.store'), ['name' => 'Injected'])->assertForbidden();
        }
        $this->assertDatabaseCount('locations', 0);
    }
}
