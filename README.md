# NetPrior

**A Multi-Criteria Decision Support System for Network Infrastructure Upgrade Prioritization in Higher Education Institutions.**

NetPrior extends the existing Laravel 12 / Breeze application with infrastructure inventory, dynamic assessments, AHP weighting and consistency checks, explainable upgrade rankings, historical decision snapshots, institutional reporting, and role-based administration. It uses Blade, Tailwind, Vite, PHP and MySQL without additional application packages.

## Current status

Phases 1–12 are implemented. Existing authentication is preserved. Migrations have been applied to the configured local MySQL database, the production frontend has been built, and reference roles, asset types, and criteria have been seeded. A clearly labeled local demo dataset was subsequently added on request; see [demo data](docs/netprior-demo-data.md).

See [implementation and verification](docs/netprior-implementation.md), [decision methodology](docs/netprior-methodology.md), and the [UI/UX design system and responsive QA](docs/netprior-ui.md).

See also [ranking freshness, coverage, and demo labels](docs/netprior-freshness-and-demo.md).

## Open the application

For large inventories, see [batch assessment entry](docs/netprior-batch-assessments.md): assess up to 20 assets per page, resume drafts and complete selected rows.

Recent improvements are described in [workflow usability](docs/netprior-workflow-usability.md), including unsaved-change warnings, searchable asset selection, remembered filters, assessment queues and saved-run comparison.

Use the existing XAMPP virtual host with its document root set to `public`, or run:

```powershell
php artisan serve
```

Open the URL shown by Artisan. Register an account through Breeze. New accounts have no institutional role until assigned. To designate your existing account as the first administrator, replace the email below:

```powershell
php artisan netprior:assign-role "your-existing-email@example.edu" administrator
```

Administrators can then create users and assign roles through Administration → Users. Other built-in role codes are `evaluator` and `management`. Role changes through the console are audited; no default administrator password is provided.

## Demo data

The local database now includes 6 demo locations, 12 assets, 12 assessments and a demo-only prioritization run. Open the dashboard or Priority Rankings to explore it. Existing AHP judgments and account credentials were preserved. Repeat the opt-in seeder without duplicating records:

```powershell
php artisan db:seed --class=DemoDataSeeder
```

See [demo dataset details](docs/netprior-demo-data.md).

## Workflow assistance

The dashboard now includes a permission-aware readiness checklist. Assessment forms provide criterion-specific 1–5 descriptions, editable through Decision criteria and preserved in new assessment snapshots. See [readiness and scoring guides](docs/netprior-readiness-and-rubrics.md).

## Normal workflow

1. Add the institution's locations and review asset types.
2. Review the starter criteria, directions, and evaluator guidance.
3. Register network assets.
4. Create draft assessments and complete their criterion scores.
5. Enter AHP pairwise judgments and save calculated weights. CR must be at most 0.10.
6. Run prioritization using the latest completed assessment of each eligible asset.
7. Review rankings, contribution breakdowns, recommendations, and skipped assets.
8. Print management reports and preserve runs for future comparison.

Settings configure the institution name, reassessment interval, and priority thresholds. Built-in role permissions are fixed; custom roles receive explicitly selected abilities. Deactivate users or master records and retire assets when history prevents deletion.

## Applying changes on another installation

Configure `.env` for that installation's database, URL, mail and timezone. Use an existing Laravel application key; generate one only on a new installation that has no key.

```powershell
composer install
npm.cmd ci
php artisan migrate
php artisan db:seed
npm.cmd run build
```

The default seeder creates only roles, common asset types, and starter criteria. It does not create users, locations, asset assessments, judgments, or results. Existing reference records are preserved. An optional local-only DevelopmentSeeder is documented in the implementation guide.

`APP_TIMEZONE` controls dates and assessment-date validation. This workspace is configured for `Asia/Singapore`; `.env.example` defaults to UTC. Existing credentials were preserved.

## Verification

```powershell
php artisan test --compact
php artisan route:list --except-vendor
php artisan view:cache
npm.cmd run build
```

Local MySQL integration verification uses a rolled-back transaction and checks that the original table counts are preserved:

```powershell
php scripts/verify-mysql.php
```

The dependency-free layout check requires Node 24 and local Chrome. It consumes the private QA HTML generated by the MySQL check:

```powershell
node scripts/verify-layout.mjs
```

Its results and screenshots are stored under the ignored `storage/app/qa` directory. `NETPRIOR_CHROME` can point to another installed Chrome executable. No browser package or remote service is required.

## Decision support, scope and policy

AHP supports 1–10 active criteria using the supplied Random Index table. Scores use a directional 1–5 rubric mapped to 0–100. Priority thresholds are configurable institutional policy, not scientifically validated cutoffs. Reports print the current filtered page; technical assessment reports require technical access. Prioritization is synchronous and intended for institutional inventory sizes; large deployments should measure runtime before adopting their own operating limits.

NetPrior provides evidence-based prioritization recommendations to assist ICT personnel and institutional decision-makers. Final upgrade decisions remain with authorized institutional personnel.
