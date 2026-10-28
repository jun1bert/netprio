<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class PageContext
{
    public static function for(Request $request): array
    {
        $name = $request->route()?->getName() ?? '';
        $group = explode('.', $name)[0];
        $groups = [
            'dashboard' => ['Overview', 'Monitor infrastructure health and focus the next upgrade discussion.'],
            'assets' => ['Infrastructure', 'Manage and evaluate your institution’s network infrastructure.'],
            'locations' => ['Locations', 'Organize infrastructure by building, floor, and operational area.'],
            'asset-types' => ['Asset types', 'Keep infrastructure categories consistent across your institution.'],
            'criteria' => ['Decision criteria', 'Define what matters and give evaluators a shared scoring rubric.'],
            'ahp' => ['Decision support', 'Turn expert judgments into consistent, transparent criterion weights.'],
            'assessments' => ['Assessments', 'Record evidence, evaluate upgrade need, and preserve assessment history.'],
            'runs' => ['Priority rankings', 'Review evidence-based recommendations and trace every priority score.'],
            'reports' => ['Reports', 'Prepare clear, traceable reports for institutional review.'],
            'users' => ['User accounts', 'Manage institutional access while preserving decision history.'],
            'roles' => ['Institutional roles', 'Give each team member the access their responsibilities require.'],
            'settings' => ['Institution settings', 'Set the policy used by future assessments and prioritization runs.'],
            'audit' => ['Audit trail', 'Review the actions behind your institution’s decision records.'],
            'administration' => ['Administration', 'Manage access, accountability, and institutional policy.'],
            'profile' => ['My profile', 'Manage your account information and security.'],
        ];
        [$label,$description] = $groups[$group] ?? ['NetPrior', 'Network infrastructure decision support.'];
        $breadcrumbs = [];
        if (preg_match('/\.(create|edit|show|result|compare|batch)$/', $name)) {
            if (auth()->user()?->can('view-dashboard')) {
                $breadcrumbs[] = ['label' => 'Dashboard', 'url' => route('dashboard')];
            }
            $listAbilities = ['assets' => 'view-assets', 'locations' => 'view-assets', 'asset-types' => 'view-assets', 'criteria' => 'view-criteria', 'assessments' => 'view-assessments', 'runs' => 'view-rankings', 'reports' => 'view-reports', 'users' => 'manage-users', 'roles' => 'manage-roles'];
            if (Route::has($group.'.index') && (! isset($listAbilities[$group]) || auth()->user()?->can($listAbilities[$group]))) {
                $breadcrumbs[] = ['label' => $label, 'url' => route($group.'.index')];
            }
            if (in_array($name, ['runs.result', 'runs.compare'], true) && $request->route('run')) {
                $breadcrumbs[] = ['label' => 'Run results', 'url' => route('runs.show', $request->route('run'))];
            }
        }

        return compact('label', 'description', 'breadcrumbs');
    }
}
