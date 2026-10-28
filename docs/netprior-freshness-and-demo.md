# Ranking freshness, coverage, and demonstration labels

New completed runs save versioned SHA-256 fingerprints of active criteria, scoring guidance, accepted weights, active pairwise comparisons, institutional policy, scoped asset details, and latest completed assessment evidence. Freshness is checked without modifying historical runs or scores. New draft assessments do not invalidate a completed ranking.

Dashboard, ranking details, explanations and run-based reports show whether inputs match, have changed, or cannot be fully verified. Older snapshots remain unchanged: detectable differences are explained, but the application never reconstructs a fingerprint and calls it an original baseline. Assessment age is reported separately under the current reassessment interval.

Coverage counts refer to the original run, with ranked assets divided by active/under-maintenance candidates. Total exclusions also include inactive and retired candidates. Current scorable counts are displayed separately. Legacy coverage is derived from the saved results and exclusion reasons.

Authorized administrators and evaluators can use **Run again** for changed or unverifiable runs. It opens a named, reviewable form and creates a new historical run on submission. A scoped run retains its original selected IDs; an all-assets run includes the current inventory. The source run ID is recorded in the new snapshot. Management users cannot initiate reruns. GET requests do not create or recalculate runs.

The `is_demo` asset flag explicitly identifies seeded synthetic records. Migration `2026_10_27_000005` backfills only assets carrying the existing `[NETPRIOR_DEMO_V1]` seed marker. Ordinary asset forms cannot assign or clear this flag. Renaming a demo asset does not remove it. Future run snapshots retain each ranked asset's flag and a run-level provenance summary. Existing snapshots use preserved DEMO codes/names as a cautious legacy fallback, with that limitation stated.

An authenticated workspace ribbon warns when synthetic inventory exists. Asset and assessment lists label individual demo records. Ranking history labels runs that include demo results. Dashboards, run details, explanations, and reports distinguish all-demo and mixed data; report warnings remain visible in print. Live inventory and assessment report labels reflect the active filters, while saved ranking provenance describes the entire run.

Verification uses SQLite feature tests, a rolled-back local MySQL integration check, production frontend compilation, and responsive browser/print checks. These checks preserve the existing local demo run, AHP judgments, users, and credentials.