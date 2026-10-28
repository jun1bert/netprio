<?php

use App\Models\Role;

return [
    'thresholds' => ['critical' => 80, 'high' => 60, 'medium' => 40],
    'reassessment_months' => 12,
    'institution_name' => 'NetPrior',
    // Unknown or unassigned roles receive no domain permissions.
    'permissions' => [
        'view-dashboard' => [Role::ADMINISTRATOR, Role::EVALUATOR, Role::MANAGEMENT],
        'view-assets' => [Role::ADMINISTRATOR, Role::EVALUATOR, Role::MANAGEMENT],
        'view-criteria' => [Role::ADMINISTRATOR, Role::EVALUATOR, Role::MANAGEMENT],
        'view-assessments' => [Role::ADMINISTRATOR, Role::EVALUATOR],
        'create-assessments' => [Role::ADMINISTRATOR, Role::EVALUATOR],
        'manage-assessments' => [Role::ADMINISTRATOR],
        'view-rankings' => [Role::ADMINISTRATOR, Role::EVALUATOR, Role::MANAGEMENT],
        'view-reports' => [Role::ADMINISTRATOR, Role::EVALUATOR, Role::MANAGEMENT],
        'view-technical-reports' => [Role::ADMINISTRATOR, Role::EVALUATOR],
        'manage-assets' => [Role::ADMINISTRATOR],
        'manage-master-data' => [Role::ADMINISTRATOR],
        'manage-criteria' => [Role::ADMINISTRATOR],
        'manage-comparisons' => [Role::ADMINISTRATOR],
        'run-prioritization' => [Role::ADMINISTRATOR],
        'manage-users' => [Role::ADMINISTRATOR],
        'manage-roles' => [Role::ADMINISTRATOR],
        'view-audit-logs' => [Role::ADMINISTRATOR],
        'manage-settings' => [Role::ADMINISTRATOR],
        'access-administration' => [Role::ADMINISTRATOR],
    ],
];
