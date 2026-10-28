<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Criterion;
use App\Models\NetworkAsset;
use App\Models\PrioritizationResult;
use App\Models\PrioritizationRun;
use App\Models\Role;
use App\Models\User;
use App\Services\AssessmentService;
use App\Support\WorkspaceNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsNetpriorRecords;
use Tests\TestCase;

class RoleNavigationTest extends TestCase
{
    use BuildsNetpriorRecords,RefreshDatabase;

    public static function roles(): array
    {
        return [['administrator', true, true], ['evaluator', true, false], ['management', false, false]];
    }

    #[DataProvider('roles')]
    public function test_navigation_and_routes_match_builtin_permissions(string $role, bool $assessmentAccess, bool $administration): void
    {
        $user = $this->institutionUser($role);
        $this->actingAs($user);
        $dashboard = $this->get(route('dashboard'))->assertOk();
        $this->assertSame('dashboard', WorkspaceNavigation::home($user));
        foreach (['assets.index', 'locations.index', 'asset-types.index', 'criteria.index', 'ahp.index', 'ahp.weights', 'runs.index', 'reports.index', 'profile.edit'] as $route) {
            $this->get(route($route))->assertOk();
        }
        foreach (['assessments.index', 'assessments.create', 'assessments.batch'] as $route) {
            $this->get(route($route))->assertStatus($assessmentAccess ? 200 : 403);
        }
        foreach (['assets.create', 'criteria.create', 'ahp.store', 'runs.create', 'users.index', 'roles.index', 'audit.index', 'settings.edit', 'administration.index'] as $route) {
            if ($route === 'ahp.store') {
                continue;
            }
            $this->get(route($route))->assertStatus($administration ? 200 : 403);
        }
        $this->get(route('reports.show', 'assessments'))->assertStatus($assessmentAccess ? 200 : 403);
        if ($assessmentAccess) {
            $dashboard->assertSee('Batch assessments')->assertSee('Recent assessments');
        } else {
            $dashboard->assertDontSee('Recent assessments')->assertDontSee('Mean completed score')->assertDontSee('href="'.route('assessments.batch').'"', false);
        }
        if (! $administration) {
            $dashboard->assertDontSee('href="'.route('runs.create').'"', false)->assertDontSee('href="'.route('users.index').'"', false);
        }
    }

    public function test_unassigned_accounts_land_on_profile_with_clear_access_guidance(): void
    {
        $user = User::factory()->create();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('profile.edit'));
        $this->get(route('profile.edit'))->assertOk()->assertSee('No institutional role assigned')->assertSee('Ask your institution administrator')->assertDontSee('href="'.route('assets.index').'"', false);
        $this->get(route('dashboard'))->assertForbidden();
        $this->get(route('login'))->assertRedirect(route('profile.edit'));
    }

    public function test_custom_dashboard_links_only_point_to_accessible_modules(): void
    {
        $role = Role::create(['code' => 'overview-only', 'name' => 'Overview only', 'permissions' => ['view-dashboard']]);
        $user = User::factory()->create();
        $user->role()->associate($role)->save();
        $this->actingAs($user);
        $page = $this->get(route('dashboard'))->assertOk();
        foreach (['assets.index', 'assessments.create', 'assessments.batch', 'criteria.index', 'ahp.weights', 'runs.index', 'reports.index', 'users.index'] as $route) {
            $page->assertDontSee('href="'.route($route).'"', false);
        }
        $role->update(['permissions' => ['view-assets']]);
        $user->unsetRelation('role');
        $this->assertSame('assets.index', WorkspaceNavigation::home($user));
        $this->get(route('assets.index'))->assertOk();
        $this->get(route('dashboard'))->assertForbidden();
    }

    public function test_inventory_view_does_not_expose_restricted_assessment_evidence(): void
    {
        $admin = $this->institutionUser();
        $this->actingAs($admin);
        $criterion = Criterion::create(['code' => 'PERF', 'name' => 'Performance', 'direction' => 'benefit']);
        $asset = NetworkAsset::create($this->assetValues());
        app(AssessmentService::class)->save($admin, ['network_asset_id' => $asset->id, 'assessment_date' => today()->toDateString(), 'status' => 'completed', 'scores' => [$criterion->id => ['score' => 4, 'raw_value' => 83.23, 'raw_unit' => 'sensitive-evidence-unit']]]);
        $this->actingAs($this->institutionUser('management'))->get(route('assets.show', $asset))->assertOk()->assertDontSee('Latest completed scores')->assertDontSee('sensitive-evidence-unit')->assertDontSee('Assessment history');
        $this->get(route('assessments.show', Assessment::first()))->assertForbidden();
        $run = PrioritizationRun::create(['name' => 'Saved institutional review', 'created_by' => $admin->id, 'status' => 'completed', 'completed_at' => now(), 'decision_snapshot' => []]);
        $result = PrioritizationResult::create(['prioritization_run_id' => $run->id, 'network_asset_id' => $asset->id, 'assessment_id' => Assessment::first()->id, 'rank' => 1, 'final_score' => 75, 'priority_level' => 'high', 'recommendation' => 'Review this asset.',
            'asset_snapshot' => ['name' => $asset->name, 'asset_code' => $asset->asset_code, 'location' => 'Data center', 'type' => 'Switch', 'assessment_date' => today()->toDateString()],
            'score_breakdown' => [['name' => 'Performance', 'direction' => 'benefit', 'score' => 4, 'weight' => 1, 'contribution' => 75, 'normalized_score' => 0.75, 'raw_value' => 83.23, 'raw_unit' => 'sensitive-evidence-unit', 'remarks' => 'Technical evidence']]]);
        $this->get(route('runs.result', [$run, $result]))->assertOk()->assertDontSee('sensitive-evidence-unit')->assertDontSee('Technical evidence and calculation');
        $this->actingAs($admin)->get(route('assets.show', $asset))->assertOk()->assertSee('Latest completed scores')->assertSee('sensitive-evidence-unit');
    }

    public function test_batch_navigation_marks_the_current_page(): void
    {
        $this->actingAs($this->institutionUser('evaluator'))->get(route('assessments.batch'))->assertOk()->assertSee('sidebar-link sidebar-link-active', false)->assertSee('aria-current="page"', false)->assertSee('Batch assessments');
    }
}
