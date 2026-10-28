<?php

namespace App\Support;

use App\Models\User;

class WorkspaceNavigation
{
    public static function home(User $user): string
    {
        foreach ([['view-dashboard', 'dashboard'], ['view-assessments', 'assessments.index'], ['create-assessments', 'assessments.create'], ['view-assets', 'assets.index'], ['view-rankings', 'runs.index'], ['view-reports', 'reports.index'], ['view-criteria', 'ahp.weights'], ['run-prioritization', 'runs.create'], ['manage-assets', 'assets.create'], ['manage-criteria', 'criteria.create'], ['manage-master-data', 'locations.create'], ['manage-users', 'users.index'], ['manage-roles', 'roles.index'], ['view-audit-logs', 'audit.index'], ['manage-settings', 'settings.edit'], ['access-administration', 'administration.index']] as [$ability,$route]) {
            if ($user->can($ability)) {
                return $route;
            }
        }

        return 'profile.edit';
    }

    public static function tasks(User $user): array
    {
        return collect([
            ['assessments.batch', 'Batch assessments', 'Assess a group or resume editable drafts.', 'create-assessments', 'clipboard'],
            ['assets.index', $user->can('create-assessments') ? 'Assessment queue' : 'Network inventory', $user->can('create-assessments') ? 'Find assets, unfinished drafts and overdue reviews.' : 'Browse equipment, locations and operational status.', 'view-assets', 'network'],
            ['runs.index', 'Review rankings', 'Compare saved runs and understand upgrade priorities.', 'view-rankings', 'chart'],
            ['reports.index', 'Open reports', 'Review and print institutional findings.', 'view-reports', 'file'],
            ['ahp.index', 'Review AHP judgments', 'Check comparisons and accept consistent weights.', 'manage-comparisons', 'settings'],
        ])->filter(fn ($task) => $user->can($task[3]) && ($task[0] !== 'ahp.index' || $user->can('view-criteria')))->take(3)->values()->all();
    }
}
