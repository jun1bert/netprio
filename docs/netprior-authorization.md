# NetPrior — Phase 3 authorization

> This records the Phase 3 baseline. Phase 11 now adds explicit custom-role permissions, account deactivation, and administrative screens. Unknown roles receive no access by default; administrators can explicitly grant abilities to custom roles. See [current implementation](netprior-implementation.md).

Phase 3 adds a deny-by-default permission map, Laravel gates, model policies, protected routes and a trusted console role-assignment command. Breeze remains the authentication system. No authorization package or database migration was needed.

## Access model

| Capability | Administrator | Evaluator | Management |
| --- | --- | --- | --- |
| Dashboard, inventory, locations/types, criteria/weights, completed rankings, general reports | Yes | Yes | Yes |
| Technical assessments and technical reports | Yes | Yes | No |
| Create assessments | Yes | Yes | No |
| Edit/delete draft assessments | Any draft | Own drafts | No |
| Edit/delete completed assessments | No | No | No |
| Configure infrastructure, criteria and comparisons | Yes | No | No |
| Create/manage draft prioritization runs | Yes | No | No |
| View draft prioritization runs/results | Yes | No | No |
| Edit individual results or completed runs | No | No | No |
| Users, roles, settings, audit review, administration page | Yes | No | No |
| Edit/delete audit records through user-facing authorization | No | No | No |

Accounts with no role or an unknown role code receive no domain permissions. Profile management and logout remain available to authenticated accounts. The standard role codes are `administrator`, `evaluator`, and `management`. Role display names do not determine privileges. Configuring additional role codes requires an explicit permission-map change; creating a role record alone does not grant access.

`config/netprior.php` defines named abilities; AppServiceProvider registers their gates. Policies in `app/Policies` are discovered by Laravel's model/policy naming convention. There is no blanket administrator bypass, so completed-record and audit protections apply to administrators too. Laravel's built-in `can` middleware protects `/dashboard` and `/administration`; `auth` protects both routes from guests. The existing `verified` middleware remains in place; this phase does not enable mandatory email verification or change Breeze's User verification contract.

Administration is an access landing page, not yet the complete user/settings management module. Navigation displays its link only to authorized users. A 403 page explains role assignment and provides profile/logout links. Login and registration still redirect to the existing dashboard route; an unassigned account receives this access-restricted page until assigned a role.

## Provisioning access

The role seeder is idempotent, preserves existing display names, and creates only role records. It was run against the configured MySQL database. DatabaseSeeder now invokes RoleSeeder instead of creating Laravel's default sample account. No existing account was promoted automatically.

Register an account normally, then run this from a trusted server terminal, replacing the sample email with the existing account email:

```powershell
php artisan netprior:assign-role "your-existing-email@example.edu" administrator
```

Other supported role assignments:

```powershell
php artisan netprior:assign-role "evaluator@example.edu" evaluator
php artisan netprior:assign-role "manager@example.edu" management
```

The console command validates role codes and existing accounts, saves the change and an audit log in one transaction, and treats assigning the same role as a no-op. Role assignment serializes through a lock on the administrator role row. It refuses to demote the last administrator. Console audit entries identify their source as console with a null authenticated actor, rather than claiming the target account performed the assignment.

`role_id` remains outside User's fillable attributes. Registration and profile endpoints accept only their validated fields; posted role fields cannot grant privileges. The console command is an operation for trusted server operators; it is not a public HTTP endpoint.

## Account deletion

Profile deletion verifies the current password, then checks decision history inside a transaction before deleting or logging out. Assessments, comparisons or prioritization runs prevent deletion with a visible `userDeletion.account` validation message. The last administrator cannot delete their account. Failed deletion leaves the account and authenticated session intact. Foreign-key restrictions remain a second line of protection, including a friendly response for a concurrent MySQL foreign-key deletion rejection. Audit history survives otherwise permitted account deletion.

## Verification and commands

Executed successfully:

```powershell
php vendor/bin/pint app/Policies app/Console/Commands app/Models/Role.php app/Models/User.php app/Providers/AppServiceProvider.php app/Http/Controllers/ProfileController.php config/netprior.php database/seeders routes/web.php tests/Feature/AuthorizationTest.php
php artisan test
php artisan db:seed --class=RoleSeeder
php artisan route:list --path=administration -vv
php artisan route:list --path=dashboard -vv
php artisan view:cache
php artisan migrate:status
php artisan help netprior:assign-role
```

Result: **58 tests passed, 449 assertions**. Tests use isolated in-memory SQLite. Coverage includes all three roles, unassigned/unknown roles, guests, policy discovery, assessment ownership, completed history protections, draft-result visibility, HTTP privilege escalation attempts, idempotent seeding/role assignment, role-change auditing, last-administrator safeguards and account-deletion error rendering. Existing Breeze and foundation tests pass.

## Integration requirements for later phases

Future controllers must invoke policies or `can` middleware for each action; policies do not automatically intercept arbitrary model writes. For lists, use the viewAny gate and restrict draft result queries to administrators. For new score rows, authorize update of the parent assessment, take assessed_by from the authenticated actor, and validate ownership/foreign keys from authorized records. Recheck draft status while holding a database lock during changes or completion. Apply the same administrator role-row lock and last-administrator check to future role/deletion HTTP workflows.

Authorization does not implement the later assessment completion, immutable history services, CRUD, AHP, ranking, user/role editing UI, account deactivation or general audit-writing workflows. Those remain in their corresponding phases. No known failing checks remain for Phase 3.