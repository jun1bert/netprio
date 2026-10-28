# Workflow readiness and scoring guides

The dashboard checklist and criterion-specific scoring rubrics are implemented.

## Dashboard checklist

Decision workflow appears after the dashboard KPIs. It opens by default when prerequisites are blocked and is collapsible when at least one asset can be ranked. It reports four steps:

1. Register active/maintenance assets.
2. Complete assessments with every currently active criterion score.
3. Accept consistent AHP weights that match the current comparisons.
4. Run and review prioritization.

Coverage uses the same latest-completed-assessment selection and eligible asset statuses as the decision engine. Newer drafts never hide completed assessments. Retired/inactive assets do not inflate the eligible count. Partial coverage is explicitly shown: a run can proceed when at least one eligible asset has every active score and AHP weights are accepted/consistent; missing assets will be skipped. The checklist does not require every asset to be assessed before a run.

The dedicated Missing current criterion scores inventory filter finds both unassessed assets and assets whose latest completed assessment lacks newly activated criteria. The assessment queue remains the separate age-based reassessment view. Readiness measures current criterion coverage, not assessment freshness. A preserved run is identified as available history; it is not represented as automatically current after subsequent input changes.

Links use the current user's abilities. Management users receive review links rather than evaluator/admin mutation links. Dashboard-only custom roles receive no links to modules they cannot access. An inconsistency, incomplete matrix, unsupported criterion count, or unaccepted/stale weights is shown as a blocker without changing judgments or stored weights.

The existing local demo dataset was verified as 8 fully scored of 10 eligible assets, with 2 missing score coverage, and accepted consistent weights. The checklist therefore correctly permits a partial run while identifying outstanding assessments.

## Scoring rubrics

Criteria now support an optional rubric JSON field containing five descriptions, keyed by scores 1–5. Administrators can edit it at Decision criteria → Edit → Define score descriptions (1–5).

- Provide all five descriptions to define an institutional rubric.
- Leave all five blank to use suggested/general guidance.
- Partial rubrics, unknown score keys and descriptions over 1,000 characters are rejected.
- Existing rubric values are retained when an older API/client omits the rubric field.

The seven starter criterion codes have suggested qualitative anchors for performance shortfall, condition, downtime, utilization, criticality, security exposure and upgrade financial burden. Suggested guidance is used only with its expected scoring direction. Custom/renamed-code criteria without institutional descriptions use a clearly labeled general benefit/cost scale. Suggestions are illustrative qualitative anchors, not empirically validated numerical thresholds or automatic raw-measurement conversions.

Assessment sections now show these meanings beside scoring, offer a five-description disclosure, and use the actual criterion rubric in the selected-score explanation and accessible quick-score labels. Unscored sections start with the guide open. Decimal scores remain supported and lie between adjacent anchors; raw evidence and consistent observation periods remain important.

AssessmentService snapshots all five descriptions and their source alongside each score. Subsequent criterion edits do not rewrite completed assessments or their scores. Historical assessment pages expose the recorded guide; legacy scores without such a snapshot say that guidance was not stored, rather than silently substituting current guidance. No backfill or rewrite of existing completed scores was performed.

Criterion edits retain the existing conservative weight-invalidation behavior; accept comparisons again after editing criteria. Mathematical normalization, AHP consistency rules, final scores and priorities are unchanged.

## Applied and verified

Applied migration: 2026_10_27_000004_add_criterion_scoring_rubrics.php (nullable criteria.rubric). Existing comparisons, weights, assets and completed assessments were preserved. The default guides are computed without seeding or overwriting institutional configuration.

The automated suite passes 102 tests / 867 assertions. New tests cover empty setup, partial/incomplete assessment coverage, newer drafts, retired assets, accepted/stale/inconsistent weights, role-aware links, suggested/custom/fallback rubrics, all-five validation and historical snapshot preservation.

Vite build, Blade compilation, formatting and MySQL transactional smoke checks pass. Browser QA checks 47 screen/state fixtures at 375, 768, 1024, 1366 and 1920px, with checklist expansion and five-description assessment checks added to the existing interaction suite. Private fixtures are rolled back and QA files remain under ignored storage/app/qa.

Commands executed:

```powershell
php artisan migrate
php artisan test --compact
npm.cmd run build
php artisan view:cache
php scripts/verify-mysql.php
node scripts/verify-layout.mjs
```