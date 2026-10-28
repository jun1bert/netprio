# Demo dataset

The requested test dataset was added locally with:

```powershell
php artisan db:seed --class=DemoDataSeeder
```

It contains 6 DEMO-prefixed locations, 12 DEMO-coded assets, 11 completed assessments, 1 draft and one `[DEMO] Campus network upgrade review` run. The run ranks 8 assets and records 4 exclusions: unassessed, draft-only, inactive and retired assets. The core switch has an older assessment for exploring history.

The calculated distribution at insertion was 2 Critical, 2 High, 2 Medium and 2 Low assets. These scores were calculated by the real services using existing accepted judgments (CR 0.09363863217376432). Rankings and weights were not hard-coded.

Records and assessment evidence are labeled synthetic demonstrations. The sample network addresses use 192.0.2.0/24 and locally administered MAC examples. No accounts or passwords were created or changed.

The seeder is opt-in, local/testing-only and separate from DatabaseSeeder. It preserves existing records, user edits, completed history and expert judgments. Repeating it does not duplicate records. If no active comparisons exist, it initializes clearly reported equal-importance demonstration judgments through AhpService. Incomplete, inconsistent or unaccepted existing judgments are preserved; a warning explains why no run could be created.

PrioritizationService accepts an optional internal asset scope. Normal application runs still consider all candidate assets. The demo run selects only demo asset IDs and snapshots that scope, so unrelated real assets are not mixed into demo results.

Verification: 96 tests passed, 808 assertions, including idempotency, preservation of judgments/weights and real records, demo-only ranking scope, history and production rejection. No schema changes were needed.