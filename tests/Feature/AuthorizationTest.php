<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssetType;
use App\Models\AuditLog;
use App\Models\Criterion;
use App\Models\Location;
use App\Models\NetworkAsset;
use App\Models\PairwiseComparison;
use App\Models\PrioritizationResult;
use App\Models\PrioritizationRun;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function account(?string $code): User
    {
        $user = User::factory()->create();
        if ($code !== null) {
            $role = Role::firstOrCreate(['code' => $code], ['name' => $code]);
            $user->role()->associate($role)->save();
        }

        return $user;
    }

    public static function roleAccess(): array
    {
        return [
            'administrator' => [Role::ADMINISTRATOR, true, true, true],
            'evaluator' => [Role::EVALUATOR, true, false, true],
            'management' => [Role::MANAGEMENT, true, false, false],
            'unassigned' => [null, false, false, false],
            'unknown' => ['unrecognized', false, false, false],
        ];
    }

    #[DataProvider('roleAccess')]
    public function test_routes_and_domain_permissions(?string $code, bool $read, bool $admin, bool $technical): void
    {
        $user = $this->account($code);
        $this->actingAs($user)->get(route('dashboard'))->assertStatus($read ? 200 : 403);
        $this->get(route('administration.index'))->assertStatus($admin ? 200 : 403);
        $this->get(route('profile.edit'))->assertOk();
        foreach (['view-assets', 'view-criteria', 'view-rankings', 'view-reports'] as $ability) {
            $this->assertSame($read, $user->can($ability), $ability);
        }
        foreach (['view-assessments', 'create-assessments', 'view-technical-reports'] as $ability) {
            $this->assertSame($technical, $user->can($ability), $ability);
        }
        foreach (['manage-assets', 'manage-master-data', 'manage-criteria', 'manage-comparisons',
            'run-prioritization', 'manage-users', 'manage-roles', 'manage-settings', 'view-audit-logs'] as $ability) {
            $this->assertSame($admin, $user->can($ability), $ability);
        }
        // Exercise Laravel policy discovery as well as the permission gates.
        foreach ([Location::class, AssetType::class, NetworkAsset::class, Criterion::class, PairwiseComparison::class] as $model) {
            $this->assertSame($read, $user->can('viewAny', $model), $model);
            $this->assertSame($admin, $user->can('create', $model), $model);
            $this->assertSame($admin, $user->can('update', new $model), $model);
            $this->assertSame($admin, $user->can('delete', new $model), $model);
        }
        foreach ([User::class, Role::class] as $model) {
            $this->assertSame($admin, $user->can('viewAny', $model));
            $this->assertSame($admin, $user->can('create', $model));
        }
        $this->assertSame($admin, $user->can('viewAny', AuditLog::class));
        $this->assertFalse($user->can('update', new AuditLog));
        $this->assertFalse($user->can('delete', new AuditLog));
    }

    public function test_guests_cannot_access_protected_routes_or_permissions(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('administration.index'))->assertRedirect(route('login'));
        $this->assertFalse(Gate::allows('manage-users'));
    }

    public function test_only_owner_evaluator_or_admin_can_change_drafts_and_completed_assessments_are_locked(): void
    {
        $owner = $this->account(Role::EVALUATOR);
        $other = $this->account(Role::EVALUATOR);
        $admin = $this->account(Role::ADMINISTRATOR);
        $manager = $this->account(Role::MANAGEMENT);
        $draft = new Assessment(['assessed_by' => $owner->id, 'status' => 'draft']);
        $score = (new AssessmentScore)->setRelation('assessment', $draft);
        foreach ([$owner, $admin] as $user) {
            $this->assertTrue($user->can('update', $draft));
            $this->assertTrue($user->can('delete', $draft));
            $this->assertTrue($user->can('update', $score));
        }
        foreach ([$other, $manager, $this->account(null)] as $user) {
            $this->assertFalse($user->can('update', $draft));
            $this->assertFalse($user->can('delete', $score));
        }
        $draft->status = 'completed';
        foreach ([$owner, $other, $admin, $manager] as $user) {
            $this->assertFalse($user->can('update', $draft));
            $this->assertFalse($user->can('delete', $draft));
            $this->assertFalse($user->can('update', $score));
        }
    }

    public function test_management_can_review_completed_results_but_not_draft_runs_or_edit_results(): void
    {
        $manager = $this->account(Role::MANAGEMENT);
        $admin = $this->account(Role::ADMINISTRATOR);
        $draft = new PrioritizationRun(['status' => 'draft']);
        $result = (new PrioritizationResult)->setRelation('prioritizationRun', $draft);
        $this->assertFalse($manager->can('view', $draft));
        $this->assertFalse($manager->can('view', $result));
        $this->assertTrue($admin->can('view', $draft));
        $this->assertTrue($admin->can('update', $draft));
        $draft->status = 'completed';
        $this->assertTrue($manager->can('view', $draft));
        $this->assertTrue($manager->can('view', $result));
        $this->assertFalse($admin->can('update', $draft));
        $this->assertFalse($admin->can('delete', $draft));
        $this->assertFalse($admin->can('update', $result));
        $this->assertFalse($manager->can('create', PrioritizationRun::class));
    }

    public function test_registration_and_profile_cannot_assign_roles(): void
    {
        $role = Role::create(['code' => Role::ADMINISTRATOR, 'name' => 'Administrator']);
        $this->post(route('register'), [
            'name' => 'Applicant', 'email' => 'applicant@example.test',
            'password' => 'ValidPassword123!', 'password_confirmation' => 'ValidPassword123!',
            'role_id' => $role->id, 'role' => Role::ADMINISTRATOR,
        ])->assertRedirect(route('profile.edit', absolute: false));
        $user = User::where('email', 'applicant@example.test')->firstOrFail();
        $this->assertNull($user->role_id);
        $this->patch(route('profile.update'), [
            'name' => 'Updated', 'email' => $user->email, 'role_id' => $role->id,
        ])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->role_id);
        $this->get(route('administration.index'))->assertForbidden();
    }

    public function test_role_seeding_and_console_assignment_are_idempotent_and_audited(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->assertSame(3, Role::count());
        $user = $this->account(null);
        $this->artisan('netprior:assign-role', ['email' => $user->email, 'role' => 'administrator'])->assertSuccessful();
        $this->assertTrue($user->fresh()->hasRole(Role::ADMINISTRATOR));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.role_assigned', 'entity_id' => $user->id]);
        $this->artisan('netprior:assign-role', ['email' => $user->email, 'role' => 'administrator'])->assertSuccessful();
        $this->assertSame(1, AuditLog::count());
        $this->artisan('netprior:assign-role', ['email' => $user->email, 'role' => 'management'])->assertFailed();
        $this->assertTrue($user->fresh()->hasRole(Role::ADMINISTRATOR));
        $second = $this->account(Role::ADMINISTRATOR);
        $this->artisan('netprior:assign-role', ['email' => $user->email, 'role' => 'management'])->assertSuccessful();
        $this->assertTrue($user->fresh()->hasRole(Role::MANAGEMENT));
        $this->assertTrue($second->fresh()->hasRole(Role::ADMINISTRATOR));
        $this->assertSame(2, AuditLog::count());
    }

    public function test_console_rejects_unknown_role_and_missing_user_without_changes(): void
    {
        $this->seed(RoleSeeder::class);
        $this->artisan('netprior:assign-role', ['email' => 'absent@example.test', 'role' => 'administrator'])->assertFailed();
        $this->artisan('netprior:assign-role', ['email' => 'absent@example.test', 'role' => 'superuser'])->assertFailed();
        $this->assertSame(0, AuditLog::count());
    }

    public static function historyTypes(): array
    {
        return [['assessment'], ['comparison'], ['run']];
    }

    #[DataProvider('historyTypes')]
    public function test_account_with_decision_history_cannot_be_deleted_and_remains_authenticated(string $history): void
    {
        $user = $this->account(Role::EVALUATOR);
        if ($history === 'run') {
            PrioritizationRun::create(['name' => 'Historical run', 'created_by' => $user->id]);
        } elseif ($history === 'comparison') {
            $first = Criterion::create(['code' => 'A', 'name' => 'A', 'direction' => 'benefit']);
            $second = Criterion::create(['code' => 'B', 'name' => 'B', 'direction' => 'benefit']);
            PairwiseComparison::create([
                'criterion_a_id' => $first->id, 'criterion_b_id' => $second->id,
                'comparison_value' => 1, 'created_by' => $user->id,
            ]);
        } else {
            $location = Location::create(['name' => 'Library']);
            $type = AssetType::create(['name' => 'Switch']);
            $asset = NetworkAsset::create([
                'location_id' => $location->id, 'asset_type_id' => $type->id,
                'asset_code' => 'SW-001', 'name' => 'Switch',
            ]);
            Assessment::create([
                'network_asset_id' => $asset->id, 'assessed_by' => $user->id,
                'assessment_date' => '2026-10-27',
            ]);
        }
        $this->actingAs($user)->from(route('profile.edit'))->delete(route('profile.destroy'), [
            'password' => 'password',
        ])->assertRedirect(route('profile.edit'))->assertSessionHasErrorsIn('userDeletion', 'account');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());
        $this->get(route('profile.edit'))->assertSee('This account has decision history');
    }

    public function test_last_administrator_cannot_delete_account_but_another_administrator_can(): void
    {
        $admin = $this->account(Role::ADMINISTRATOR);
        $this->actingAs($admin)->from(route('profile.edit'))->delete(route('profile.destroy'), [
            'password' => 'password',
        ])->assertSessionHasErrorsIn('userDeletion', 'account');
        $this->assertAuthenticatedAs($admin);
        $other = $this->account(Role::ADMINISTRATOR);
        $this->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');
        $this->assertGuest();
        $this->assertNull($admin->fresh());
        $this->assertNotNull($other->fresh());
    }
}
