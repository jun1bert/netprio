# NetPrior implementation and verification

## Delivered phases

| Phase | Implemented behavior | Main implementation |
| --- | --- | --- |
| 1–2 | Normalized database, role link, models, typed relationships and casts | database/migrations, app/Models |
| 3 | Permission gates, policies, trusted role assignment, last-administrator protection | config/netprior.php, app/Policies, AssignRole command |
| 4 | Locations, asset types, dynamic criteria CRUD; validation, deactivation, pagination and auditing | MasterDataController, MasterDataRequest |
| 5 | Full asset CRUD, search, type/location/status/priority/assessment filters, sorting, history | NetworkAssetController, NetworkAssetRequest |
| 6 | Dynamic 1–5 scores, raw measurements/units, evidence, evaluator ownership and completion snapshots | AssessmentService, AssessmentController, AssessmentRequest |
| 7 | Genuine AHP matrices, reciprocal input, eigenvector, weights, CI/CR and consistency warnings | AhpService, AhpController |
| 8 | Weighted scoring, exclusions, deterministic ties, priorities, explanations, immutable run endpoints | PrioritizationService, PrioritizationController |
| 9 | Database-backed executive metrics, distributions, top priorities and recent assessments | DashboardController, dashboard Blade view |
| 10 | Seven printable reports, dates/search, historical run selection and technical access restrictions | ReportController, reports views |
| 11 | Account CRUD/deactivation, custom permission roles, audit browsing and adjustable policy settings | AccountService, administration controllers/views, SettingsService |
| 12 | Mathematical tests, HTTP workflow/security tests, MySQL transaction smoke check and browser QA | tests, scripts/verify-mysql.php, scripts/verify-layout.mjs |

## Database and architectural changes

Added migrations after the foundation:

- `2026_10_27_000002_add_assessment_score_snapshots.php`: criterion_snapshot on assessment_scores.
- `2026_10_27_000003_add_administration_settings.php`: users.is_active, roles.permissions and a JSON-backed system_settings table.

All migrations ran successfully on the configured MySQL installation. No destructive migration commands were used. Foreign keys restrict historical deletion. Custom role permissions are explicit, validated against the known ability list and granted only through an administrator workflow. Built-in role codes and permissions are fixed; their display names/descriptions remain editable. A custom role with no permissions receives no domain access.

The built-in `can` middleware and policies enforce access on all domain actions. Draft assessment status is rechecked under a database lock. AHP, assessment completion, ranking and policy changes serialize through criterion locks; account changes serialize through the administrator role row. The last active canonical administrator cannot be removed/demoted/deactivated by supported workflows. An administrator cannot change their own role or deactivate themselves through the management form.

Inactive users cannot authenticate; already-authenticated inactive accounts are signed out by web middleware. Deactivation clears remember tokens and database-backed sessions. Password changes through administration clear target remember tokens and database sessions. Role and is_active fields are excluded from public mass assignment. Public registration remains Breeze registration with no assigned institutional role. Passwords are hashed and excluded from audit payloads.

Audit events capture actor, action, entity and timestamp. Entity keys with string primary keys, such as system settings, are saved in metadata rather than forced into the numeric entity_id column. Audit records have no user-facing editing/deletion routes.

## Interface and reporting

The advanced UI/UX update is complete. See [design system and responsive verification](netprior-ui.md) for the current shell, mobile cards, AHP wizard, assessment UX, feedback and five-breakpoint QA.

The application uses a navy sidebar, neutral panels, responsive forms/tables, readable priorities and a collapsible mobile menu. All existing Breeze profile/authentication routes are preserved. Navigation follows permissions. The public landing, login branding and access-restricted pages identify NetPrior.

The dashboard uses actual database queries. Mean completed assessment score is an unweighted 1–5 summary across completed score rows, not a priority score; cost directions are not implicitly inverted in this descriptive metric. Due assessments are active/maintenance assets without a completed assessment or whose latest completed assessment is at least the configured interval old.

Reports cover inventory, assessments, upgrade rankings, critical infrastructure, criterion weights, AHP consistency and run history. Reports print the current filtered page and identify page/record counts, generation time, generator and institution. Date filters mean asset registration date for inventory, assessment_date for assessment reports and completed_at for run-based reports. Technical assessment reports require technical-report permission. Ranking/weight/consistency reports use selected completed-run snapshots; the AHP page shows the current configuration. When there are no matching completed runs, weights/consistency reports can show the current calculation with consistency status.

## Reference and optional development data

Executed `php artisan db:seed --class=ReferenceDataSeeder`: nine common asset types and seven configurable starter criteria. RoleSeeder has also run. Weights remain uncalculated until expert judgments are accepted. No assets, assessments, pairwise judgments or rankings were seeded into the application database.

Default DatabaseSeeder now calls RoleSeeder and ReferenceDataSeeder, both idempotently preserving existing reference values. Introducing new criteria clears accepted weights. Locations are supplied by each institution.

DevelopmentSeeder is optional, was **not** run, and refuses production environments. To use it locally, set a private `NETPRIOR_DEMO_PASSWORD` environment value of at least 12 characters, then run:

```powershell
php artisan db:seed --class=DevelopmentSeeder
```

It adds sample locations and, only if absent, `demo-admin@example.test` with the supplied password. It does not fabricate assessment scores or rankings, overwrite an existing demo account, or provide a default password. Do not commit the private password.

## First administrator and operation

No existing user was silently promoted. Register or use an existing account, then designate the intended administrator through the trusted terminal:

```powershell
php artisan netprior:assign-role "your-existing-email@example.edu" administrator
```

Subsequent accounts can be managed through Administration → Users. The console command also accepts evaluator and management. Use the roles UI for explicit custom roles.

All database migrations and reference seeding have already been applied in this workspace. The local APP_TIMEZONE is Asia/Singapore, with config/app.php reading APP_TIMEZONE; the sample environment uses UTC. Database credentials and existing application keys were preserved. Configure deployment-specific URLs, mail and timezone in that installation's environment and serve only the public directory.

## Validation evidence

The final suite passes **94 tests, 793 assertions** using PHPUnit's isolated in-memory SQLite database. Coverage includes:

- known AHP matrices, normalization, eigenvector, lambda max, CI/CR, reciprocal scales, malformed/incomplete matrices, and one/two/ten-criterion cases;
- directional weighted totals, exact ties, policy boundaries, exclusions, stale weights, no eligible assets and transactional failure behavior;
- master-data and inventory CRUD, uniqueness/range validation, deactivated criteria, ownership, assessment transfer rejection, historical snapshots and deletion restrictions;
- anonymous/unassigned/unknown/inactive accounts, all built-in roles, custom permissions, attempted public privilege escalation, role-change auditing, last-administrator safeguards, account deletion feedback and technical-report privacy;
- a full HTTP workflow producing the known **87.5/100** decision result;
- empty/populated dashboard and all report pages, date validation, historical reports, reference seeder idempotency and production rejection by the optional development seeder.

The configured MySQL check separately verified reciprocal precision (including 1/9), actual CHECK and unique constraints, assessment and ranking services, settings audit payloads, JSON snapshots and nine rendered screens. All fixture writes were rolled back, and original counts in twelve application tables were verified unchanged. The script is local/testing-only and never runs migrate:fresh or destructive DDL.

Chrome DevTools checks exercised nine screens at 1440-pixel desktop and **390-pixel mobile** widths: dashboard, AHP, assessment, asset, rankings, result explanation, report, settings and user form. All 18 checks reported no horizontal page overflow or JavaScript exceptions. The mobile menu opened correctly. The print-media check verified that navigation, filters and sidebar offset disappear. Screenshots were visually inspected; QA files reside in the ignored storage/app/qa directory and are not served publicly.

The production Vite build succeeds. Route discovery and Blade compilation succeed. An actual local HTTP server returned 200 for the branded landing page, login page and compiled CSS; the temporary server was stopped after checking.

Executed verification commands:

```powershell
php artisan migrate
php artisan db:seed --class=ReferenceDataSeeder
php vendor/bin/pint app config/netprior.php config/app.php routes/web.php bootstrap/app.php database tests scripts/verify-mysql.php
php artisan test --compact
php artisan route:list --except-vendor
php artisan view:cache
php artisan migrate:status
npm.cmd run build
php scripts/verify-mysql.php
node scripts/verify-layout.mjs
```

## Practical limits

The supplied RI table intentionally caps AHP at ten active criteria. Institutions must calibrate scoring guidance and priority thresholds to their own objectives. Ranking runs are synchronous and eager-load candidate assets; this implementation has not been load-tested for very large inventories. Printing is browser-based, includes the current filtered report page, and does not require a PDF service. Direct database or unrestricted Eloquent writes are outside the user-facing policy enforcement boundary; supported controllers/services protect completed decision history.

No known failing checks remain. Institutional judgments, real asset registrations, real assessments and first-administrator assignment remain operational inputs, not generated data.