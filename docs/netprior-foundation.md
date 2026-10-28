# NetPrior — database foundation and models

> This phase record describes the original foundation. Phases 1–12 are now implemented; see [current implementation and verification](netprior-implementation.md) for the complete state.

## Implemented scope

Phase 1 (database foundation) and Phase 2 (models and relationships) are implemented. Laravel 12.70.1, Breeze, existing authentication routes, controllers, and Blade layouts are preserved. The configured MySQL `netprior` database was reachable and its existing three Laravel migrations had already run. No credentials were printed or changed.

The additive migration `2026_10_27_000001_create_netprior_foundation.php` was applied successfully. It creates roles, locations, asset_types, network_assets, criteria, assessments, assessment_scores, pairwise_comparisons, prioritization_runs, prioritization_results, and audit_logs. It adds a nullable role_id foreign key to users. All eleven new domain models and Role have explicit fillable fields, typed relationships, and appropriate casts; User has role, assessments, prioritizationRuns, pairwiseComparisons, and auditLogs relationships.

No sample users, assets, scores, comparisons, weights, or rankings were inserted. Existing users remain unassigned to a role. No packages were added.

## Schema decisions and assumptions

- A user has at most one role initially. Role codes/names are configurable data. Role assignment is deliberately excluded from User mass assignment; a trusted administrative workflow must assign it after authorization is implemented in Phase 3. Nullable roles must grant no domain privileges.
- Stored statuses/directions/priority levels use lowercase machine values (`under_maintenance`, `completed`, `benefit`, etc.); later views should supply readable labels.
- Scores use a 1–5 range, support fractional values, and store optional numeric raw_value plus raw_unit. Draft assessments can omit score rows. Completing an assessment must later validate that all required active criteria are present.
- Criterion weights are null until calculated and accepted. Values, when set, must lie between zero and one. Their total, consistency, and freshness after criterion/comparison changes must be checked by the future AHP service.
- Pairwise records must use criterion_a_id < criterion_b_id. Services must reorder incoming IDs and reciprocate the comparison value when necessary. The database rejects self-comparisons, reversed storage, duplicate pairs, and values outside [1/9, 9]. The later Form Request must restrict inputs to the discrete Saaty scale and its reciprocals; the schema's range check alone does not enforce that discrete set.
- Monetary acquisition cost uses DECIMAL(15,2); scoring, comparisons, weights, CI, CR, and final scores use double precision without display rounding at storage time. Result scores must lie between zero and 100; rank starts at one. Rank is intentionally nonunique to allow ties; tie policy belongs in the later ranking service.
- Asset types have unique names. Locations allow repeated names across buildings/floors. Asset codes and criterion codes are unique.
- Foreign keys restrict deletion of referenced assets, assessments, scores, criteria, runs, locations, asset types, roles, and users with decision activity. Deactivation/retirement is the intended domain workflow. Deleting an assessment's scores explicitly and then its unreferenced draft will require an authorized transaction.
- Audit records retain action, entity, description, metadata and timestamp. Only the optional actor foreign key becomes null when an otherwise unreferenced user is deleted; the eventual audit writer should include an actor display snapshot in metadata. Entity references are descriptive, so logs survive deletion of their subject.
- Run decision_snapshot is reserved for criteria identities/names/directions, calculated weights, comparison matrix, consistency metrics, thresholds, scoring method and algorithm version. Each result requires asset_snapshot and score_breakdown. Snapshot storage survives edits to source names, criteria, scores and settings. Later services must populate these fields completely and prevent mutation/deletion of completed records. These lifecycle rules are not yet implemented.
- The migration uses CHECK constraints on MySQL/MariaDB. SQLite tests use equivalent insert/update triggers because SQLite cannot add CHECK constraints to existing tables. MySQL/MariaDB must support enforced CHECK constraints. SQLite tests and successful MySQL DDL application are complementary checks, not a full MySQL behavioral test suite.

## Commands executed

```powershell
php artisan --version
php artisan migrate:status
php artisan migrate --pretend
php artisan migrate
php artisan test --filter=NetpriorFoundationTest
php artisan test
php artisan route:list --except-vendor
php artisan view:cache
```

Additional formatting check: `php vendor/bin/pint app/Models database/migrations/2026_10_27_000001_create_netprior_foundation.php tests/Feature/NetpriorFoundationTest.php`.

The normal command for applying the foundation on another configured installation is `php artisan migrate`. Do not use migrate:fresh or migrate:refresh against an existing installation: those commands destroy data. The down migration is intended only for deliberately reverting this foundation; it drops the new domain tables and their contents.

## Verification

Foundation tests use the configured PHPUnit in-memory SQLite database, not the application's MySQL database. They verify all model relationships, date/JSON/money/float casts, active scopes, snapshot retention after source edits, numeric precision, score/ratio bounds, unique constraints, canonical comparison ordering, historical foreign-key protection, and audit retention. Existing Breeze authentication, registration, password, and profile tests remain part of the full suite.

## Phase 2 follow-up verification

Added `Assessment::completed()` and `PrioritizationRun::completed()` query scopes and `NetworkAsset::latestCompletedAssessment()`. The latter selects by assessment_date descending and ID descending for equal dates, filtering completed status inside the aggregate. It supports eager loading and returns null for assets without completed assessments. It does not imply that an assessment contains every score required by a future decision run; scoring completeness remains a service validation responsibility.

Added tests for date ordering, tied dates, newer drafts, unassessed assets, draft-only assets, eager loading, and completed-run filtering. Executed `php artisan test`: **43 tests passed, 122 assertions** using in-memory SQLite. Formatting passed with Pint. No migrations or database data changes were needed for this follow-up.

## Remaining phases

Phases 3–12 are now implemented. See [current implementation](netprior-implementation.md) for the complete system and verification evidence.

Phase 3 replaces the raw database rejection during Breeze account deletion with a clear validation response that preserves the signed-in session. Accounts with decision history and the last administrator are protected. Phase 11 now provides account deactivation and administrative user/role management screens.

NetPrior provides evidence-based prioritization recommendations to assist ICT personnel and institutional decision-makers. Final upgrade decisions remain with authorized institutional personnel.
