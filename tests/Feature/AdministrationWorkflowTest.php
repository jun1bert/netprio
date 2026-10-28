<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Models\Role;
use App\Models\User;
use App\Services\AhpService;
use App\Services\PrioritizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsNetpriorRecords;
use Tests\TestCase;

class AdministrationWorkflowTest extends TestCase
{
    use BuildsNetpriorRecords, RefreshDatabase;

    public function test_admin_can_create_manage_accounts_and_deactivate_access(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $role = Role::create(['code' => 'evaluator', 'name' => 'Evaluator']);
        $values = ['name' => 'New evaluator', 'email' => 'new@example.test', 'role_id' => $role->id, 'is_active' => 1, 'password' => 'SecurePassword123!', 'password_confirmation' => 'SecurePassword123!'];
        $this->get(route('users.create'))->assertOk();
        $this->post(route('users.store'), $values)->assertRedirect(route('users.index'))->assertSessionHasNoErrors();
        $account = User::where('email', $values['email'])->firstOrFail();
        $this->assertTrue(Hash::check($values['password'], $account->password));
        $this->get(route('users.index'))->assertOk()->assertSee('New evaluator');
        $this->get(route('users.edit', $account))->assertOk();
        $values['is_active'] = 0;
        unset($values['password'],$values['password_confirmation']);
        $this->put(route('users.update', $account), $values)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($account->fresh()->is_active);
        $this->assertFalse($account->fresh()->can('view-assets'));
        $this->get(route('audit.index'))->assertOk()->assertSee('user.updated')->assertDontSee('SecurePassword123!');
        $this->actingAs($account->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post(route('login'), ['email' => $account->email, 'password' => 'SecurePassword123!'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_self_demotion_and_nonadministrator_access_are_blocked(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $this->put(route('users.update', $admin), ['name' => $admin->name, 'email' => $admin->email, 'role_id' => '', 'is_active' => 0])->assertSessionHasErrors('role_id');
        $this->assertTrue($admin->fresh()->hasRole('administrator'));
        $this->actingAs($this->institutionUser('management'));
        foreach (['users.index', 'roles.index', 'settings.edit', 'audit.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->put(route('settings.update'), ['critical' => 1])->assertForbidden();
        $this->post(route('roles.store'), ['code' => 'injected'])->assertForbidden();
    }

    public function test_custom_roles_have_only_explicit_permissions_and_builtins_remain_protected(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $this->get(route('roles.index'))->assertOk();
        $this->get(route('roles.create'))->assertOk();
        $this->post(route('roles.store'), ['code' => 'reviewer', 'name' => 'Reviewer', 'permissions' => ['view-assets', 'view-rankings']])->assertRedirect()->assertSessionHasNoErrors();
        $role = Role::where('code', 'reviewer')->firstOrFail();
        $this->get(route('roles.edit', $role))->assertOk();
        $reviewer = User::factory()->create();
        $reviewer->role()->associate($role)->save();
        $this->assertTrue($reviewer->fresh()->can('view-assets'));
        $this->assertFalse($reviewer->fresh()->can('manage-assets'));
        $this->delete(route('roles.destroy', $role))->assertSessionHasErrors('role');
        $this->delete(route('roles.destroy', $admin->role))->assertSessionHasErrors('role');
        $this->put(route('roles.update', $admin->role), ['code' => 'changed', 'name' => 'Changed'])->assertSessionHasErrors('code');
        $this->post(route('roles.store'), ['code' => 'bad', 'name' => 'Bad', 'permissions' => ['unknown-ability']])->assertSessionHasErrors();
    }

    public function test_settings_validate_order_and_new_policy_does_not_rewrite_historical_run(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $criterion = Criterion::create(['code' => 'SEC', 'name' => 'Security', 'direction' => 'benefit']);
        app(AhpService::class)->save($admin, []);
        $asset = NetworkAsset::create($this->assetValues());
        $assessment = Assessment::create(['network_asset_id' => $asset->id, 'assessed_by' => $admin->id, 'assessment_date' => today(), 'status' => 'completed']);
        $assessment->assessmentScores()->create(['criterion_id' => $criterion->id, 'score' => 4]);
        $old = app(PrioritizationService::class)->run($admin, 'Before policy change');
        $this->assertSame('high', $old->prioritizationResults()->first()->priority_level);
        $this->get(route('settings.edit'))->assertOk();
        $values = ['institution_name' => 'Example HEI', 'reassessment_months' => 6, 'critical' => 70, 'high' => 50, 'medium' => 30];
        $this->put(route('settings.update'), array_replace($values, ['critical' => 20]))->assertSessionHasErrors('critical');
        $this->put(route('settings.update'), $values)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('system_settings', ['key' => 'policy']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated', 'entity_id' => null]);
        $new = app(PrioritizationService::class)->run($admin, 'After policy change');
        $this->assertSame('critical', $new->prioritizationResults()->first()->priority_level);
        $this->assertSame('high', $old->prioritizationResults()->first()->priority_level);
        $this->assertSame(80, $old->fresh()->decision_snapshot['thresholds']['critical']);
        $this->get(route('reports.show', ['rankings', 'run_id' => $new->id]))->assertOk()->assertSee('Example HEI');
    }
}
